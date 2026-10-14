<?php

declare(strict_types=1);

namespace App\Football\Scoring;

/** A group of metrics that measure one aspect of a season, e.g. attacking output. */
final readonly class Component
{
    /** @param non-empty-list<Metric> $metrics weights are relative within the component */
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public float $weight,
        public array $metrics,
    ) {
    }

    /** @return array{key: string, label: string, description: string, weight: float, metrics: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'description' => $this->description,
            'weight' => $this->weight,
            'metrics' => array_map(static fn (Metric $m) => $m->toArray(), $this->metrics),
        ];
    }
}
