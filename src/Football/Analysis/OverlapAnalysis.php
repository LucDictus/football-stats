<?php

declare(strict_types=1);

namespace App\Football\Analysis;

use App\Football\Scoring\PlayerScoreResult;
use App\Football\Scoring\ScoringModel;

/**
 * Roadmap 5.2, avoid double-counting: which information actually drives a model's final
 * score, per role, over the ranked players. Pure and deterministic.
 *
 * Variance decomposition: the final score F is linear in its parts,
 *   F = (1 − w) × P + w × I,  P = a + b × Σ termᵢ,  I = c + d × (Σ goalsⱼ − replacement),
 * with termᵢ = a component's share of the composite (its weight over the weight that could be
 * measured, × its score; 0 when unknown). The share of F's variance a part explains is
 *   share(x) = coefficient × Cov(x, F) / Var(F),
 * and the shares add up to 1 exactly; only clamping (models without an impact part, whose
 * positional score is clamped to 0–100) leaves a residual. A part that correlates with the
 * rest gets credit for what it shares with it, so two parts measuring the same thing show up
 * as one family with a large share. Shares describe the spread within a role, not where a
 * role lands in the shared ranking: that is what the counterfactuals show.
 *
 * Families group the parts that measure the same thing in both halves of the score.
 *
 * Counterfactuals (models with an impact part), each a full re-ranking across roles:
 * - "residual": the positional half replaced by what it adds beyond the contribution (its
 *   residual after regressing it on the contribution score within the role, around the same
 *   mean, at its own size: rescaling it would blow up what little is left);
 * - "drop …": the duplicated components left out of the positional composite, which is then
 *   standardised again to the role's positional mean and SD.
 *
 * @phpstan-type Pair array{a: string, b: string, r: float}
 * @phpstan-type RoleAnalysis array{players: int, shares: array<string, float>, families: array<string, float>, residual: float, positional_impact_correlation: ?float, minutes_correlation: ?float, final_sd: float, impact_sd: ?float, overlaps: list<Pair>, metric_overlaps: list<Pair>}
 * @phpstan-type Variant array{key: string, label: string, top: array<int, array<string, int>>, best: array<string, int>, leaders: list<string>}
 */
final class OverlapAnalysis
{
    /** Positional component or impact component (prefixed "impact.") => family. */
    public const FAMILIES = [
        'attacking_output' => 'attack',
        'attacking_quality' => 'attack',
        'attacking' => 'attack',
        'impact.shooting' => 'attack',
        'impact.finishing' => 'attack',
        'creation' => 'creation',
        'link_up' => 'creation',
        'impact.creation' => 'creation',
        'build_up' => 'build_up',
        'impact.build_up' => 'build_up',
        'defending' => 'defending',
        'duels' => 'defending',
        'impact.defence' => 'defending',
        'shot_stopping' => 'goalkeeping',
        'impact.goalkeeping' => 'goalkeeping',
        'reliability' => 'reliability',
        'impact.errors' => 'reliability',
        'distribution' => 'build_up',
        'availability' => 'volume',
        'impact.replacement' => 'volume',
    ];

    /** Positional components that repeat a contribution part, per role: the "drop" counterfactuals. */
    public const DUPLICATES = [
        'FW' => 'attacking_quality', // xG + xA per 90 ↔ shooting and creation in goals
        'MF' => 'creation',          // xA, key passes, big chances ↔ creation in goals
        'GK' => 'shot_stopping',     // goals prevented per 90 ↔ goalkeeping in goals
    ];

    /** Pairs of parts correlated at least this much are listed as overlapping. */
    public const OVERLAP_THRESHOLD = 0.6;

    /** Parts explaining less than this share of the final score are left out of the overlap pairs. */
    public const MIN_SHARE = 0.01;

    /**
     * @param list<PlayerScoreResult> $results
     *
     * @return array{roles: array<string, RoleAnalysis>, counterfactuals: list<Variant>}
     */
    public function analyse(ScoringModel $model, array $results): array
    {
        $byRole = [];
        foreach ($results as $r) {
            if ($r->rank !== null && $r->score !== null) {
                $byRole[(string) $r->candidate->positionGroup][] = $r;
            }
        }
        ksort($byRole);

        $analysis = [];
        foreach ($byRole as $role => $rows) {
            $analysis[$role] = $this->role($model, $rows);
        }

        return ['roles' => $analysis, 'counterfactuals' => $model->impact !== null ? $this->counterfactuals($model, $byRole) : []];
    }

    /**
     * @param array<string, list<PlayerScoreResult>> $byRole
     *
     * @return list<Variant>
     */
    private function counterfactuals(ScoringModel $model, array $byRole): array
    {
        $variants = [self::variant('model', $model->id() . ' as is', $byRole, static fn (PlayerScoreResult $r) => (float) $r->score)];

        $w = $model->impactWeight;
        $residual = [];
        foreach ($byRole as $rows) {
            $positional = array_map(static fn (PlayerScoreResult $r) => (float) $r->positional, $rows);
            $impact = array_map(static fn (PlayerScoreResult $r) => (float) ($r->impact['score'] ?? 0.0), $rows);
            $b = self::slope($impact, $positional);
            $meanI = array_sum($impact) / count($impact);
            foreach ($rows as $i => $r) {
                // Same mean as the positional score, without the part the contribution explains.
                $residual[spl_object_id($r)] = (1 - $w) * ($positional[$i] - $b * ($impact[$i] - $meanI)) + $w * $impact[$i];
            }
        }
        $variants[] = self::variant('residual', 'Positional half: only what it adds beyond the contribution', $byRole, static fn (PlayerScoreResult $r) => $residual[spl_object_id($r)]);

        $all = [];
        foreach (self::DUPLICATES as $role => $component) {
            if (!isset($byRole[$role])) {
                continue;
            }
            $dropped = $this->withoutComponent($w, $byRole[$role], $component);
            $all += $dropped;
            $variants[] = self::variant("drop_{$role}", "{$role}: without {$component}", $byRole, static fn (PlayerScoreResult $r) => $dropped[spl_object_id($r)] ?? (float) $r->score);
        }
        $variants[] = self::variant('drop_all', 'All three left out', $byRole, static fn (PlayerScoreResult $r) => $all[spl_object_id($r)] ?? (float) $r->score);

        return $variants;
    }

    /**
     * Final scores of a role with one positional component left out: the composite over the
     * remaining components, standardised again to the positional score's mean and SD.
     *
     * @param list<PlayerScoreResult> $rows
     *
     * @return array<int, float> by spl_object_id
     */
    private function withoutComponent(float $w, array $rows, string $component): array
    {
        $composite = [];
        foreach ($rows as $i => $r) {
            $sum = 0.0;
            $known = 0.0;
            foreach ($r->components as $key => $c) {
                if ($key !== $component && $c['score'] !== null) {
                    $sum += $c['weight'] * $c['score'];
                    $known += $c['weight'];
                }
            }
            $composite[$i] = $known > 0 ? $sum / $known : 0.0;
        }
        $composite = array_values($composite);
        $positional = array_map(static fn (PlayerScoreResult $r) => (float) $r->positional, $rows);
        $meanC = array_sum($composite) / count($composite);
        $sdC = sqrt(self::covariance($composite, $composite));
        $meanP = array_sum($positional) / count($positional);
        $sdP = sqrt(self::covariance($positional, $positional));

        $final = [];
        foreach ($rows as $i => $r) {
            $p = $sdC > 0 ? $meanP + $sdP * ($composite[$i] - $meanC) / $sdC : $meanP;
            $final[spl_object_id($r)] = (1 - $w) * $p + $w * (float) ($r->impact['score'] ?? 0.0);
        }

        return $final;
    }

    /**
     * Ranks every player by $score and counts each role in the top 10 and top 100.
     *
     * @param array<string, list<PlayerScoreResult>> $byRole
     * @param callable(PlayerScoreResult): float     $score
     *
     * @return Variant
     */
    private static function variant(string $key, string $label, array $byRole, callable $score): array
    {
        $all = [];
        foreach ($byRole as $role => $rows) {
            foreach ($rows as $r) {
                $all[] = ['name' => $r->candidate->name, 'role' => $role, 'rank' => (int) $r->rank, 'score' => $score($r)];
            }
        }
        usort($all, static fn (array $x, array $y) => [$y['score'], $x['rank']] <=> [$x['score'], $y['rank']]);

        $top = [];
        $best = [];
        foreach ($all as $i => $x) {
            $best[$x['role']] ??= $i + 1;
            foreach ([10, 100] as $n) {
                $top[$n][$x['role']] ??= 0;
                if ($i < $n) {
                    ++$top[$n][$x['role']];
                }
            }
        }

        return [
            'key' => $key,
            'label' => $label,
            'top' => $top,
            'best' => $best,
            'leaders' => array_map(static fn (array $x) => sprintf('%s (%s)', $x['name'], $x['role']), array_slice($all, 0, 10)),
        ];
    }

    /**
     * @param list<PlayerScoreResult> $rows
     *
     * @return RoleAnalysis
     */
    private function role(ScoringModel $model, array $rows): array
    {
        $w = $model->impact !== null ? $model->impactWeight : 0.0;
        $final = array_map(static fn (PlayerScoreResult $r) => (float) $r->score, $rows);
        $positional = array_map(static fn (PlayerScoreResult $r) => (float) ($model->impact !== null ? $r->positional : $r->score), $rows);

        // Positional terms: each component's share of the composite.
        $terms = [];
        foreach ($rows as $i => $r) {
            $known = array_sum(array_map(static fn (array $c) => $c['score'] !== null ? $c['weight'] : 0.0, $r->components));
            foreach ($r->components as $key => $c) {
                $terms[$key][$i] = $c['score'] !== null && $known > 0 ? $c['weight'] / $known * $c['score'] : 0.0;
            }
        }
        $composite = array_map(static fn (int $i) => array_sum(array_column($terms, $i)), array_keys($rows));
        $b = self::slope($composite, $positional);

        $shares = [];
        foreach ($terms as $key => $values) {
            $shares[$key] = (1 - $w) * $b * self::share(self::complete($values, count($rows)), $final);
        }

        // Impact terms: goals per component, minus the replacement level.
        $parts = [];
        if ($model->impact !== null) {
            $impactScore = array_map(static fn (PlayerScoreResult $r) => (float) ($r->impact['score'] ?? 0.0), $rows);
            $above = array_map(static fn (PlayerScoreResult $r) => (float) ($r->impact['above'] ?? 0.0), $rows);
            $d = self::slope($above, $impactScore);
            foreach ($rows as $i => $r) {
                foreach ($r->impact['goals'] ?? [] as $key => $goals) {
                    $parts['impact.' . $key][$i] = $goals ?? 0.0;
                }
                $parts['impact.replacement'][$i] = -($r->impact['replacement'] ?? 0.0);
            }
            foreach ($parts as $key => $values) {
                $shares[$key] = $w * $d * self::share(self::complete($values, count($rows)), $final);
            }
        }

        $families = [];
        foreach ($shares as $key => $share) {
            $family = self::FAMILIES[$key] ?? $key;
            $families[$family] = ($families[$family] ?? 0.0) + $share;
        }
        arsort($shares);
        arsort($families);

        $impactScores = $model->impact !== null ? array_map(static fn (PlayerScoreResult $r) => (float) ($r->impact['score'] ?? 0.0), $rows) : null;
        $series = array_map(
            fn (array $v) => self::complete($v, count($rows)),
            array_filter($terms + $parts, static fn (string $key) => abs($shares[$key]) >= self::MIN_SHARE, ARRAY_FILTER_USE_KEY),
        );

        return [
            'players' => count($rows),
            'shares' => $shares,
            'families' => $families,
            'residual' => 1.0 - array_sum($shares),
            'positional_impact_correlation' => $model->impact !== null
                ? self::correlation($positional, array_map(static fn (PlayerScoreResult $r) => (float) ($r->impact['score'] ?? 0.0), $rows))
                : null,
            'minutes_correlation' => self::correlation(array_map(static fn (PlayerScoreResult $r) => $r->candidate->stats->getMinutes(), $rows), $final),
            'final_sd' => sqrt(self::covariance($final, $final)),
            'impact_sd' => $impactScores === null ? null : sqrt(self::covariance($impactScores, $impactScores)),
            'overlaps' => self::overlaps($series),
            'metric_overlaps' => self::overlaps($this->metrics($rows)),
        ];
    }

    /**
     * Metric scores per metric (positional half), missing values as the metric's mean.
     *
     * @param list<PlayerScoreResult> $rows
     *
     * @return array<string, list<float>>
     */
    private function metrics(array $rows): array
    {
        $values = [];
        foreach ($rows as $i => $r) {
            foreach ($r->components as $c) {
                foreach ($c['metrics'] as $key => $m) {
                    $values[$key][$i] = $m['percentile'];
                }
            }
        }

        return array_map(static function (array $v) use ($rows) {
            $known = array_filter($v, static fn (?float $x) => $x !== null);
            $mean = $known === [] ? 0.0 : array_sum($known) / count($known);

            return array_map(static fn (int $i) => $v[$i] ?? $mean, array_keys($rows));
        }, $values);
    }

    /**
     * @param array<string, list<float>> $series
     *
     * @return list<array{a: string, b: string, r: float}> strongest first
     */
    private static function overlaps(array $series): array
    {
        $keys = array_keys($series);
        $pairs = [];
        foreach ($keys as $i => $a) {
            foreach (array_slice($keys, $i + 1) as $b) {
                $r = self::correlation($series[$a], $series[$b]);
                if ($r !== null && abs($r) >= self::OVERLAP_THRESHOLD) {
                    $pairs[] = ['a' => (string) $a, 'b' => (string) $b, 'r' => $r];
                }
            }
        }
        usort($pairs, static fn (array $x, array $y) => abs($y['r']) <=> abs($x['r']));

        return $pairs;
    }

    /**
     * @param array<int, float> $values
     *
     * @return list<float>
     */
    private static function complete(array $values, int $count): array
    {
        return array_map(static fn (int $i) => $values[$i] ?? 0.0, range(0, $count - 1));
    }

    /**
     * Cov(x, y) / Var(y).
     *
     * @param list<float> $x
     * @param list<float> $y
     */
    private static function share(array $x, array $y): float
    {
        $var = self::covariance($y, $y);

        return $var > 0 ? self::covariance($x, $y) / $var : 0.0;
    }

    /**
     * Least-squares slope of y on x.
     *
     * @param list<float> $x
     * @param list<float> $y
     */
    private static function slope(array $x, array $y): float
    {
        $var = self::covariance($x, $x);

        return $var > 0 ? self::covariance($x, $y) / $var : 0.0;
    }

    /**
     * @param list<float> $x
     * @param list<float> $y
     */
    public static function correlation(array $x, array $y): ?float
    {
        $vx = self::covariance($x, $x);
        $vy = self::covariance($y, $y);

        return $vx > 0 && $vy > 0 ? self::covariance($x, $y) / sqrt($vx * $vy) : null;
    }

    /**
     * @param list<float> $x
     * @param list<float> $y
     */
    private static function covariance(array $x, array $y): float
    {
        $n = count($x);
        if ($n < 2) {
            return 0.0;
        }
        $mx = array_sum($x) / $n;
        $my = array_sum($y) / $n;
        $sum = 0.0;
        foreach ($x as $i => $v) {
            $sum += ($v - $mx) * ($y[$i] - $my);
        }

        // Population covariance (÷ n), like the T-scores of Normalization.
        return $sum / $n;
    }
}
