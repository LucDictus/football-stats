<?php

declare(strict_types=1);

namespace App\Football\Scoring;

use App\Entity\StatsScope;
use App\Football\Scoring\Impact\ImpactModel;

/**
 * A versioned, fully explicit scoring model. A model never changes once released: a change
 * is a new version, so every stored score run can be explained and reproduced.
 *
 * Without roles, every eligible player is measured on the same components and normalised
 * against all eligible players. With roles, each position group has its own components and
 * is normalised within its own group, so a score means "how good for his role".
 */
final readonly class ScoringModel
{
    /**
     * @param list<Component>                          $components     shared components (models without roles)
     * @param list<string>                             $positionGroups who is ranked (PlayerSeason position groups, or roles with $byRole)
     * @param float                                    $minConfidence  share of the model that must be measurable to be ranked
     * @param array<string, non-empty-list<Component>> $roles          position group => its components
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
        public array $roles = [],
        public Normalization $normalization = Normalization::Percentile,
        /** Normalise against ranked players only (those meeting minConfidence), then apply to all. */
        public bool $referenceRankedOnly = false,
        /** Standard scores: deviations beyond ±zCap count as ±zCap at metric level. */
        public ?float $zCap = null,
        /** Contribution in goals (roadmap phase 5), blended with the positional score. */
        public ?ImpactModel $impact = null,
        /** Weight of the contribution in the final score; the positional score gets the rest. */
        public float $impactWeight = 0.0,
        /**
         * Group players by their finer role (RoleCatalog: winger, defensive midfielder, ...)
         * instead of the source's position group (GK, DF, MF, FW). The role is then the group
         * everywhere: comparison, replacement level, possession fit.
         */
        public bool $byRole = false,
    ) {
        if (($impact === null) !== ($impactWeight === 0.0) || $impactWeight < 0 || $impactWeight > 1) {
            throw new \InvalidArgumentException("Model {$key}-v{$version}: an impact model needs a weight between 0 and 1, and a weight needs an impact model");
        }
        foreach ($positionGroups as $group) {
            if ($this->componentsFor($group) === []) {
                throw new \InvalidArgumentException("Model {$key}-v{$version} has no components for {$group}");
            }
        }
    }

    /** @return list<Component> */
    public function componentsFor(?string $positionGroup): array
    {
        return $this->roles === [] ? $this->components : ($this->roles[(string) $positionGroup] ?? []);
    }

    /** Players are compared with their own position group when the model has roles. */
    public function comparesWithinRole(): bool
    {
        return $this->roles !== [];
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
        ]
            // Optional settings appear only when used: older definitions keep their hash.
            + ($this->roles !== [] ? [
                'roles' => array_map(static fn (array $components) => array_map(static fn (Component $c) => $c->toArray(), $components), $this->roles),
                'score' => 'composite normalised within the role',
            ] : [])
            + ($this->normalization !== Normalization::Percentile ? ['normalization' => $this->normalization->value] : [])
            + ($this->referenceRankedOnly ? ['reference' => 'ranked players'] : [])
            + ($this->zCap !== null ? ['z_cap' => $this->zCap] : [])
            + ($this->impact !== null ? ['impact' => $this->impact->toArray() + ['weight' => $this->impactWeight]] : [])
            + ($this->byRole ? ['grouping' => 'role'] : []);
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
