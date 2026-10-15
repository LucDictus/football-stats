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
        /**
         * Linear possession adjustment (standards): after shrinkage and transform, the value minus
         * slope × (team possession share − possession mean). Fitted from the data per role.
         */
        public ?float $possessionSlope = null,
        public ?float $possessionMean = null,
        /**
         * Per-90 counts: when the field's provider has no record of the player, an estimate from
         * another provider: Σ coefficient × field per 90, × scale (calibrated in the standards).
         *
         * @var list<array{0: string, 1: float}>
         */
        public array $proxy = [],
        public ?float $proxyScale = null,
    ) {
        if ($shrinkage !== null && $mode === MetricMode::Rate && $sampleFields === []) {
            throw new \InvalidArgumentException("Metric {$key}: a shrunk rate needs sample fields");
        }
        if ($mode === MetricMode::Ratio && $sampleFields === []) {
            throw new \InvalidArgumentException("Metric {$key}: a ratio needs its attempts as sample fields");
        }
        if (($proxy === []) !== ($proxyScale === null) || ($proxy !== [] && $mode !== MetricMode::Per90)) {
            throw new \InvalidArgumentException("Metric {$key}: a proxy needs its scale, and only per-90 counts have one");
        }
        if (($possessionSlope === null) !== ($possessionMean === null)) {
            throw new \InvalidArgumentException("Metric {$key}: a possession slope needs the possession mean it was fitted around");
        }
        if ($possessionAdjusted && ($mode !== MetricMode::Per90 || count($fields) !== 1)) {
            throw new \InvalidArgumentException("Metric {$key}: only a single-field per-90 metric can be adjusted for possession");
        }
    }

    /**
     * Optional settings appear only when used, so older model definitions (and their hashes)
     * stay exactly the same.
     *
     * @return array{key: string, label: string, fields: list<string>, mode: string, weight: float, lower_is_better?: true, shrinkage?: float, sample_fields?: list<string>, transform?: string, possession_adjusted?: true, possession?: array{slope: float, mean: float}, proxy?: array{terms: list<array{0: string, 1: float}>, scale: float}}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'fields' => $this->fields, 'mode' => $this->mode->value, 'weight' => $this->weight]
            + ($this->lowerIsBetter ? ['lower_is_better' => true] : [])
            + ($this->shrinkage !== null ? ['shrinkage' => $this->shrinkage] : [])
            + ($this->sampleFields !== [] ? ['sample_fields' => $this->sampleFields] : [])
            + ($this->sqrt ? ['transform' => 'sqrt'] : [])
            + ($this->possessionAdjusted ? ['possession_adjusted' => true] : [])
            + ($this->possessionSlope !== null ? ['possession' => ['slope' => $this->possessionSlope, 'mean' => (float) $this->possessionMean]] : [])
            + ($this->proxy !== [] ? ['proxy' => ['terms' => $this->proxy, 'scale' => (float) $this->proxyScale]] : []);
    }
}
