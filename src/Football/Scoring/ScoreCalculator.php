<?php

declare(strict_types=1);

namespace App\Football\Scoring;

/**
 * Applies a scoring model to a set of candidates. Pure and deterministic: the same model
 * and the same statistics always give the same ranking.
 *
 * 1. Eligibility: position group and minimum minutes from the model.
 * 2. Comparison groups: all eligible players, or each position group on its own when the
 *    model has roles ("90" then means exceptional for that role).
 * 3. Per group: metric values, and how much each player's data covers the model (confidence).
 *    The reference group is everyone, or only the players who will be ranked.
 * 4. Optional shrinkage pulls values built on little evidence toward the reference average;
 *    an optional square-root transform reduces the skew of count metrics.
 * 5. Every metric is normalised (model's Normalization) against the reference group.
 * 6. A component is the weighted mean of its known metric scores; the composite is the
 *    weighted mean of the known components. What could not be measured is left out (never
 *    counted as zero) and lowers the confidence instead.
 * 7. The score is the composite, or in role models the composite normalised again within
 *    the role: averaging several metrics pulls composites toward the middle, more so with
 *    more metrics, so without this a role with many metrics could never reach the top.
 * 8. One ranking across all groups. Players below the model's minimum confidence are
 *    returned unranked (rank null), after the ranked players.
 */
final class ScoreCalculator
{
    /** Weights like 0.4 + 0.2 do not add up exactly in floating point. */
    private const EPSILON = 1e-9;

    /**
     * @param list<ScoringCandidate> $candidates
     *
     * @return list<PlayerScoreResult> best first
     */
    public function score(ScoringModel $model, array $candidates): array
    {
        $eligible = array_values(array_filter(
            $candidates,
            static fn (ScoringCandidate $c) => in_array($c->positionGroup, $model->positionGroups, true)
                && $c->stats->getScope() === $model->scope
                && $c->stats->getMinutes() >= $model->minMinutes,
        ));

        $groups = [];
        foreach ($eligible as $candidate) {
            $groups[$model->comparesWithinRole() ? (string) $candidate->positionGroup : 'all'][] = $candidate;
        }

        $scored = [];
        foreach ($groups as $group => $members) {
            $groupScores = $this->scoreGroup($model, $model->componentsFor($model->comparesWithinRole() ? $group : null), $members);
            if ($model->comparesWithinRole()) {
                $composites = array_map(static fn (array $s) => $s['composite'], $groupScores);
                $reference = $this->reference($model, array_map(static fn (array $s) => $s['confidence'], $groupScores));
                // Unclamped for the order (two players beyond the scale must not tie), clamped to 0–100 to show.
                $raw = $model->normalization->scores($composites, reference: $reference, clamp: false);
                foreach ($groupScores as $i => $s) {
                    $groupScores[$i]['raw'] = $raw[$i];
                    $groupScores[$i]['score'] = $raw[$i] === null ? null : max(0.0, min(100.0, $raw[$i]));
                }
            }
            array_push($scored, ...$groupScores);
        }

        // Ranked players first, best score first; ties broken by the unclamped score (comparable
        // across roles: both are relative to their role), minutes, then player id.
        $ranked = static fn (array $s) => $s['score'] !== null && $s['confidence'] >= $model->minConfidence - self::EPSILON;
        usort($scored, static fn (array $a, array $b) => [$ranked($b), $b['score'], $b['raw'], $b['candidate']->stats->getMinutes(), $a['candidate']->playerId]
            <=> [$ranked($a), $a['score'], $a['raw'], $a['candidate']->stats->getMinutes(), $b['candidate']->playerId]);

        $results = [];
        $rank = 0;
        foreach ($scored as $s) {
            $results[] = new PlayerScoreResult($s['candidate'], $ranked($s) ? ++$rank : null, $s['score'], $s['confidence'], $s['components'], $s['composite']);
        }

        return $results;
    }

    /**
     * Scores one comparison group on its components.
     *
     * @param list<Component>        $components
     * @param list<ScoringCandidate> $members
     *
     * @return list<array{candidate: ScoringCandidate, score: ?float, raw: ?float, composite: ?float, confidence: float, components: array<string, mixed>}>
     */
    private function scoreGroup(ScoringModel $model, array $components, array $members): array
    {
        // Raw metric values and their sample sizes, per metric, indexed like $members.
        $values = [];
        $samples = [];
        foreach ($components as $component) {
            foreach ($component->metrics as $metric) {
                $values[$metric->key] = array_map(static fn (ScoringCandidate $c) => MetricValue::of($metric, $c->stats), $members);
                $samples[$metric->key] = array_map(static fn (ScoringCandidate $c) => MetricValue::sample($metric, $c->stats), $members);
            }
        }

        // Confidence depends only on which values are known, so it is known before normalising.
        $confidence = array_map(fn (int $i) => $this->confidence($components, $values, $i), array_keys($members));
        $reference = $this->reference($model, $confidence);

        $adjusted = [];
        $normalised = [];
        foreach ($components as $component) {
            foreach ($component->metrics as $metric) {
                $adjusted[$metric->key] = $metric->shrinkage !== null
                    ? $this->shrink($values[$metric->key], $samples[$metric->key], $metric->shrinkage, $reference)
                    : $values[$metric->key];
                $scale = $metric->sqrt
                    ? array_map(static fn (?float $v) => $v === null ? null : sqrt(max(0.0, $v)), $adjusted[$metric->key])
                    : $adjusted[$metric->key];
                $normalised[$metric->key] = $model->normalization->scores($scale, $metric->lowerIsBetter, $reference, $model->zCap);
            }
        }

        $scored = [];
        foreach ($members as $i => $candidate) {
            $breakdown = [];
            $weighted = 0.0;
            $knownWeight = 0.0;
            foreach ($components as $component) {
                $metrics = [];
                $sum = 0.0;
                $known = 0.0;
                $total = 0.0;
                foreach ($component->metrics as $metric) {
                    $score = $normalised[$metric->key][$i];
                    $metrics[$metric->key] = ['value' => $values[$metric->key][$i], 'percentile' => $score, 'weight' => $metric->weight]
                        + ($metric->shrinkage !== null ? ['adjusted' => $adjusted[$metric->key][$i]] : []);
                    $total += $metric->weight;
                    if ($score !== null) {
                        $sum += $score * $metric->weight;
                        $known += $metric->weight;
                    }
                }
                $componentScore = $known > 0 ? $sum / $known : null;
                $breakdown[$component->key] = ['score' => $componentScore, 'weight' => $component->weight, 'coverage' => $known / $total, 'metrics' => $metrics];
                if ($componentScore !== null) {
                    $weighted += $componentScore * $component->weight;
                    $knownWeight += $component->weight;
                }
            }

            $composite = $knownWeight > 0 ? $weighted / $knownWeight : null;
            $scored[] = [
                'candidate' => $candidate,
                'score' => $composite,
                'raw' => $composite,
                'composite' => $composite,
                'confidence' => $confidence[$i],
                'components' => $breakdown,
            ];
        }

        return $scored;
    }

    /**
     * Share of the model's weight that could be measured for member $i.
     *
     * @param list<Component>                       $components
     * @param array<string, array<int, float|null>> $values
     */
    private function confidence(array $components, array $values, int $i): float
    {
        $measured = 0.0;
        $modelWeight = 0.0;
        foreach ($components as $component) {
            $known = 0.0;
            $total = 0.0;
            foreach ($component->metrics as $metric) {
                $total += $metric->weight;
                $known += $values[$metric->key][$i] !== null ? $metric->weight : 0.0;
            }
            $modelWeight += $component->weight;
            $measured += $component->weight * $known / $total;
        }

        return $modelWeight > 0 ? $measured / $modelWeight : 0.0;
    }

    /**
     * Keys of the members whose distribution defines "average": all, or only the players who
     * will be ranked (so scores built on very little data do not stretch the scale).
     *
     * @param array<int, float> $confidence
     *
     * @return list<int>|null null = everyone
     */
    private function reference(ScoringModel $model, array $confidence): ?array
    {
        if (!$model->referenceRankedOnly) {
            return null;
        }

        return array_keys(array_filter($confidence, static fn (float $c) => $c >= $model->minConfidence - self::EPSILON));
    }

    /**
     * Empirical Bayes: (value × n + average × prior) / (n + prior), with n the player's sample
     * (matches or attempts) and the average over the reference group.
     *
     * @param array<int, float|null> $values
     * @param array<int, float|null> $samples
     * @param list<int>|null         $reference
     *
     * @return array<int, float|null>
     */
    private function shrink(array $values, array $samples, float $prior, ?array $reference): array
    {
        $group = $reference === null ? $values : array_intersect_key($values, array_flip($reference));
        $known = array_filter($group, static fn (?float $v) => $v !== null);
        if ($known === []) {
            return $values;
        }
        $average = array_sum($known) / count($known);

        $shrunk = [];
        foreach ($values as $i => $value) {
            $n = $samples[$i];
            $shrunk[$i] = $value === null || $n === null ? $value : ($value * $n + $average * $prior) / ($n + $prior);
        }

        return $shrunk;
    }
}
