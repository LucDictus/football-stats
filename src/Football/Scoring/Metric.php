<?php

declare(strict_types=1);

namespace App\Football\Scoring;

/** One measurable quantity: the sum of one or more fields, as a season total or per 90 (see MetricMode). */
final readonly class Metric
{
    /**
     * @param non-empty-list<string> $fields       field keys from the FieldCatalog
     * @param list<string>           $sampleFields
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $fields,
        public MetricMode $mode,
        public float $weight,
        /** For metrics like errors leading to goal: fewer is better. */
        public bool $lowerIsBetter = false,
        /**
         * Empirical-Bayes shrinkage: a prior worth this many matches (per 90) or attempts
         * (rates), at the group average. Few observations are pulled toward the average,
         * many barely move: 15 out of 15 tackles is not a perfect season.
         */
        public ?float $shrinkage = null,
        /**
         * The fields whose sum is the sample size for shrinkage: attempts for rates; for per-90
         * metrics optionally an event count instead of matches (shots on target faced).
         */
        public array $sampleFields = [],
        /**
         * Square-root transform before normalising: the variance-stabilising transform for
         * count data. Counts per 90 are skewed (most players near zero, a few far above), and
         * on the raw scale a few extreme values dominate a standard score; the square root
         * reduces that without cutting off the top. Only for non-negative metrics.
         */
        public bool $sqrt = false,
        /**
         * Per-90 defensive counts: adjusted for the team's possession, since a team without the
         * ball has more to defend (PossessionAdjustment). Single-field per-90 metrics only.
         */
        public bool $possessionAdjusted = false,
    ) {
        if ($shrinkage !== null && $mode === MetricMode::Rate && $sampleFields === []) {
            throw new \InvalidArgumentException("Metric {$key}: a shrunk rate needs sample fields");
        }
        if ($possessionAdjusted && ($mode !== MetricMode::Per90 || count($fields) !== 1)) {
            throw new \InvalidArgumentException("Metric {$key}: only a single-field per-90 metric can be adjusted for possession");
        }
    }

    /**
     * Optional settings appear only when used, so older model definitions (and their hashes)
     * stay exactly the same.
     *
     * @return array{key: string, label: string, fields: list<string>, mode: string, weight: float, lower_is_better?: true, shrinkage?: float, sample_fields?: list<string>, transform?: string, possession_adjusted?: true}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'fields' => $this->fields, 'mode' => $this->mode->value, 'weight' => $this->weight]
            + ($this->lowerIsBetter ? ['lower_is_better' => true] : [])
            + ($this->shrinkage !== null ? ['shrinkage' => $this->shrinkage] : [])
            + ($this->sampleFields !== [] ? ['sample_fields' => $this->sampleFields] : [])
            + ($this->sqrt ? ['transform' => 'sqrt'] : [])
            + ($this->possessionAdjusted ? ['possession_adjusted' => true] : []);
    }
}
