<?php

declare(strict_types=1);

namespace App\Football\Scoring;

/**
 * Applies a scoring model to a set of candidates. Pure and deterministic: the same model
 * and the same statistics always give the same ranking.
 *
 * 1. Eligibility: position group and minimum minutes from the model.
 * 2. Every metric is turned into a percentile among the eligible players that have it.
 * 3. A component is the weighted mean of its known metric percentiles.
 * 4. The score is the weighted mean of the known components. What could not be measured
 *    is left out (never counted as zero) and lowers the confidence instead.
 * 5. Players below the model's minimum confidence are returned unranked (rank null), after
 *    the ranked players.
 */
final class ScoreCalculator
{
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

        // Metric values and percentiles, per metric, indexed like $eligible.
        $values = [];
        $percentiles = [];
        foreach ($model->components as $component) {
            foreach ($component->metrics as $metric) {
                $values[$metric->key] = array_map(static fn (ScoringCandidate $c) => MetricValue::of($metric, $c->stats), $eligible);
                $percentiles[$metric->key] = Percentile::ranks($values[$metric->key]);
            }
        }

        $scored = [];
        foreach ($eligible as $i => $candidate) {
            $components = [];
            $weighted = 0.0;
            $knownWeight = 0.0;
            $measured = 0.0;
            $modelWeight = 0.0;
            foreach ($model->components as $component) {
                $metrics = [];
                $sum = 0.0;
                $known = 0.0;
                $total = 0.0;
                foreach ($component->metrics as $metric) {
                    $percentile = $percentiles[$metric->key][$i];
                    $metrics[$metric->key] = ['value' => $values[$metric->key][$i], 'percentile' => $percentile, 'weight' => $metric->weight];
                    $total += $metric->weight;
                    if ($percentile !== null) {
                        $sum += $percentile * $metric->weight;
                        $known += $metric->weight;
                    }
                }
                $score = $known > 0 ? $sum / $known : null;
                $components[$component->key] = ['score' => $score, 'weight' => $component->weight, 'coverage' => $known / $total, 'metrics' => $metrics];

                $modelWeight += $component->weight;
                $measured += $component->weight * $known / $total;
                if ($score !== null) {
                    $weighted += $score * $component->weight;
                    $knownWeight += $component->weight;
                }
            }

            $scored[] = [
                'candidate' => $candidate,
                'score' => $knownWeight > 0 ? $weighted / $knownWeight : null,
                'confidence' => $modelWeight > 0 ? $measured / $modelWeight : 0.0,
                'components' => $components,
            ];
        }

        // Ranked players first, best score first; ties broken by minutes, then by player id,
        // so the order is stable.
        // Tolerance: weights like 0.4 + 0.2 do not add up exactly in floating point.
        $ranked = static fn (array $s) => $s['score'] !== null && $s['confidence'] >= $model->minConfidence - 1e-9;
        usort($scored, static fn (array $a, array $b) => [$ranked($b), $b['score'], $b['candidate']->stats->getMinutes(), $a['candidate']->playerId]
            <=> [$ranked($a), $a['score'], $a['candidate']->stats->getMinutes(), $b['candidate']->playerId]);

        $results = [];
        $rank = 0;
        foreach ($scored as $s) {
            $results[] = new PlayerScoreResult($s['candidate'], $ranked($s) ? ++$rank : null, $s['score'], $s['confidence'], $s['components']);
        }

        return $results;
    }
}
