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
 * 3. Every metric is normalised (model's Normalization) among the group members that have it.
 * 4. A component is the weighted mean of its known metric scores.
 * 5. The composite is the weighted mean of the known components. What could not be measured
 *    is left out (never counted as zero) and lowers the confidence instead.
 * 6. The score is the composite, or in role models the composite normalised again within
 *    the role: averaging several metrics pulls composites toward the middle, more so with
 *    more metrics, so without this a role with many metrics could never reach the top.
 * 7. One ranking across all groups. Players below the model's minimum confidence are
 *    returned unranked (rank null), after the ranked players.
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

        $groups = [];
        foreach ($eligible as $candidate) {
            $groups[$model->comparesWithinRole() ? (string) $candidate->positionGroup : 'all'][] = $candidate;
        }

        $scored = [];
        foreach ($groups as $group => $members) {
            $groupScores = $this->scoreGroup($model, $model->componentsFor($model->comparesWithinRole() ? $group : null), $members);
            if ($model->comparesWithinRole()) {
                $final = $model->normalization->scores(array_map(static fn (array $s) => $s['composite'], $groupScores));
                foreach ($groupScores as $i => $s) {
                    $groupScores[$i]['score'] = $final[$i];
                }
            }
            array_push($scored, ...$groupScores);
        }

        // Ranked players first, best score first; ties broken by composite, minutes, then by
        // player id, so the order is stable.
        // Tolerance: weights like 0.4 + 0.2 do not add up exactly in floating point.
        $ranked = static fn (array $s) => $s['score'] !== null && $s['confidence'] >= $model->minConfidence - 1e-9;
        usort($scored, static fn (array $a, array $b) => [$ranked($b), $b['score'], $b['composite'], $b['candidate']->stats->getMinutes(), $a['candidate']->playerId]
            <=> [$ranked($a), $a['score'], $a['composite'], $a['candidate']->stats->getMinutes(), $b['candidate']->playerId]);

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
     * @return list<array{candidate: ScoringCandidate, score: ?float, composite: ?float, confidence: float, components: array<string, mixed>}>
     */
    private function scoreGroup(ScoringModel $model, array $components, array $members): array
    {
        // Metric values and normalised scores, per metric, indexed like $members.
        $values = [];
        $normalised = [];
        foreach ($components as $component) {
            foreach ($component->metrics as $metric) {
                $values[$metric->key] = array_map(static fn (ScoringCandidate $c) => MetricValue::of($metric, $c->stats), $members);
                $normalised[$metric->key] = $model->normalization->scores($values[$metric->key], $metric->lowerIsBetter);
            }
        }

        $scored = [];
        foreach ($members as $i => $candidate) {
            $breakdown = [];
            $weighted = 0.0;
            $knownWeight = 0.0;
            $measured = 0.0;
            $modelWeight = 0.0;
            foreach ($components as $component) {
                $metrics = [];
                $sum = 0.0;
                $known = 0.0;
                $total = 0.0;
                foreach ($component->metrics as $metric) {
                    $score = $normalised[$metric->key][$i];
                    $metrics[$metric->key] = ['value' => $values[$metric->key][$i], 'percentile' => $score, 'weight' => $metric->weight];
                    $total += $metric->weight;
                    if ($score !== null) {
                        $sum += $score * $metric->weight;
                        $known += $metric->weight;
                    }
                }
                $componentScore = $known > 0 ? $sum / $known : null;
                $breakdown[$component->key] = ['score' => $componentScore, 'weight' => $component->weight, 'coverage' => $known / $total, 'metrics' => $metrics];

                $modelWeight += $component->weight;
                $measured += $component->weight * $known / $total;
                if ($componentScore !== null) {
                    $weighted += $componentScore * $component->weight;
                    $knownWeight += $component->weight;
                }
            }

            $composite = $knownWeight > 0 ? $weighted / $knownWeight : null;
            $scored[] = [
                'candidate' => $candidate,
                'score' => $composite,
                'composite' => $composite,
                'confidence' => $modelWeight > 0 ? $measured / $modelWeight : 0.0,
                'components' => $breakdown,
            ];
        }

        return $scored;
    }
}
