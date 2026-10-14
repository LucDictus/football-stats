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
        /** Rates: the fields whose sum is the number of attempts (the sample size for shrinkage). */
        public array $sampleFields = [],
    ) {
        if ($shrinkage !== null && $mode === MetricMode::Rate && $sampleFields === []) {
            throw new \InvalidArgumentException("Metric {$key}: a shrunk rate needs sample fields");
        }
    }

    /**
     * Optional settings appear only when used, so older model definitions (and their hashes)
     * stay exactly the same.
     *
     * @return array{key: string, label: string, fields: list<string>, mode: string, weight: float, lower_is_better?: true, shrinkage?: float, sample_fields?: list<string>}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'fields' => $this->fields, 'mode' => $this->mode->value, 'weight' => $this->weight]
            + ($this->lowerIsBetter ? ['lower_is_better' => true] : [])
            + ($this->shrinkage !== null ? ['shrinkage' => $this->shrinkage] : [])
            + ($this->sampleFields !== [] ? ['sample_fields' => $this->sampleFields] : []);
    }
}
