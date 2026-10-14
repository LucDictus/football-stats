<?php

declare(strict_types=1);

namespace App\Football\Scoring;

use App\Entity\StatsScope;

/**
 * A versioned, fully explicit scoring model. A model never changes once released: a change
 * is a new version, so every stored score run can be explained and reproduced.
 */
final readonly class ScoringModel
{
    /**
     * @param non-empty-list<Component> $components     weights are relative across components
     * @param list<string>              $positionGroups who is ranked (PlayerSeason position groups)
     * @param float                     $minConfidence  share of the model that must be measurable to be ranked
     */
    public function __construct(
        public string $key,
        public string $version,
        public string $label,
        public string $description,
        public StatsScope $scope,
        public float $minMinutes,
        public array $positionGroups,
        public float $minConfidence,
        public array $components,
    ) {
    }

    public function id(): string
    {
        return "{$this->key}-v{$this->version}";
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id(),
            'key' => $this->key,
            'version' => $this->version,
            'label' => $this->label,
            'description' => $this->description,
            'scope' => $this->scope->value,
            'min_minutes' => $this->minMinutes,
            'position_groups' => $this->positionGroups,
            'min_confidence' => $this->minConfidence,
            'components' => array_map(static fn (Component $c) => $c->toArray(), $this->components),
        ];
    }

    /**
     * Fingerprint of the definition: two runs with the same hash used the same model. Canonical,
     * so it can be recomputed from a stored definition: CanonicalJson::hash($run->getDefinition()).
     */
    public function hash(): string
    {
        return CanonicalJson::hash($this->toArray());
    }
}
