<?php

declare(strict_types=1);

namespace App\Football\Scoring;

/**
 * One player's score under a model, with everything needed to explain it.
 *
 * @phpstan-type MetricBreakdown array{value: ?float, percentile: ?float, weight: float, adjusted?: ?float}
 * @phpstan-type ComponentBreakdown array{score: ?float, weight: float, coverage: float, metrics: array<string, MetricBreakdown>}
 * @phpstan-type ImpactBreakdown array{goals: array<string, ?float>, total: ?float, replacement: float, above: ?float, attack_source: ?string, score: ?float, weight: float}
 */
final readonly class PlayerScoreResult
{
    /**
     * @param int|null                          $rank       null: not ranked, too little of the model could be measured
     * @param float|null                        $score      0–100, weighted over the components that could be measured
     * @param float                             $confidence 0–1, share of the model's weight that could be measured
     * @param array<string, ComponentBreakdown> $components
     * @param float|null                        $composite  weighted mean of the components; equals the score unless the model normalises within roles
     * @param float|null                        $positional the positional score, when the final score also blends in contribution
     * @param ImpactBreakdown|null              $impact     contribution in goals and its score, for models with an impact part
     */
    public function __construct(
        public ScoringCandidate $candidate,
        public ?int $rank,
        public ?float $score,
        public float $confidence,
        public array $components,
        public ?float $composite = null,
        public ?float $positional = null,
        public ?array $impact = null,
    ) {
    }
}
