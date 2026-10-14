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
 * measured, × its score). The share of F's variance a part explains is
 *   share(x) = coefficient × Cov(x, F) / Var(F),
 * and the shares add up to 1 (up to the residual: clamping, players with missing parts).
 * A part that correlates with the rest gets credit for what it shares with it, so two parts
 * measuring the same thing show up as one family with a large share.
 *
 * Families group the parts that measure the same thing in both halves of the score.
 *
 * Counterfactual (models with an impact part): the positional half replaced by what it adds
 * beyond the contribution, i.e. its residual after regressing it on the contribution score
 * within the role, standardised like a positional score (mean 50, SD 10). It shows what
 * removing the overlap would do to the ranking.
 */
/**
 * @phpstan-type Pair array{a: string, b: string, r: float}
 * @phpstan-type RoleAnalysis array{players: int, shares: array<string, float>, families: array<string, float>, residual: float, positional_impact_correlation: ?float, minutes_correlation: ?float, final_sd: float, overlaps: list<Pair>, metric_overlaps: list<Pair>}
 * @phpstan-type Counterfactual array{top: array<int, array<string, array{model: int, counterfactual: int}>>, leaders: list<array{rank: int, name: string, role: string, model_rank: int}>}
 */
final class OverlapAnalysis
{
    /** Positional component or impact component (prefixed "impact.") => family. */
    public const FAMILIES = [
        'attacking_output' => 'scoring',
        'attacking_quality' => 'scoring',
        'attacking' => 'scoring',
        'impact.shooting' => 'scoring',
        'impact.finishing' => 'scoring',
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
        'availability' => 'volume',
        'impact.replacement' => 'volume',
    ];

    /** Pairs of parts correlated at least this much are listed as overlapping. */
    public const OVERLAP_THRESHOLD = 0.6;

    /** Parts explaining less than this share of the final score are left out of the overlap pairs. */
    public const MIN_SHARE = 0.01;

    /**
     * @param list<PlayerScoreResult> $results
     *
     * @return array{roles: array<string, RoleAnalysis>, counterfactual: ?Counterfactual}
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

        return ['roles' => $analysis, 'counterfactual' => $model->impact !== null ? $this->counterfactual($model, $byRole) : null];
    }

    /**
     * @param array<string, list<PlayerScoreResult>> $byRole
     *
     * @return Counterfactual
     */
    private function counterfactual(ScoringModel $model, array $byRole): array
    {
        $w = $model->impactWeight;
        $all = [];
        foreach ($byRole as $role => $rows) {
            $positional = array_map(static fn (PlayerScoreResult $r) => (float) $r->positional, $rows);
            $impact = array_map(static fn (PlayerScoreResult $r) => (float) ($r->impact['score'] ?? 0.0), $rows);
            $b = self::slope($impact, $positional);
            $meanP = array_sum($positional) / count($positional);
            $meanI = array_sum($impact) / count($impact);
            $residual = array_map(static fn (int $i) => $positional[$i] - $meanP - $b * ($impact[$i] - $meanI), array_keys($rows));
            $sd = sqrt(self::covariance($residual, $residual));
            foreach ($rows as $i => $r) {
                $beyond = $sd > 0 ? 50 + 10 * $residual[$i] / $sd : 50.0;
                $all[] = ['name' => $r->candidate->name, 'role' => $role, 'rank' => (int) $r->rank, 'score' => (1 - $w) * $beyond + $w * $impact[$i]];
            }
        }
        usort($all, static fn (array $x, array $y) => [$y['score'], $x['rank']] <=> [$x['score'], $y['rank']]);

        $top = [];
        foreach ([10, 100] as $n) {
            foreach (array_keys($byRole) as $role) {
                $top[$n][$role] = [
                    'model' => count(array_filter($byRole[$role], static fn (PlayerScoreResult $r) => $r->rank <= $n)),
                    'counterfactual' => count(array_filter(array_slice($all, 0, $n), static fn (array $x) => $x['role'] === $role)),
                ];
            }
        }

        return [
            'top' => $top,
            'leaders' => array_map(
                static fn (array $x, int $i) => ['rank' => $i + 1, 'name' => $x['name'], 'role' => $x['role'], 'model_rank' => $x['rank']],
                array_slice($all, 0, 10),
                array_keys(array_slice($all, 0, 10)),
            ),
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

        return $sum / ($n - 1);
    }
}
