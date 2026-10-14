<?php

declare(strict_types=1);

namespace App\Entity;

use App\Football\Data\FieldCatalog;
use App\Football\Data\Origin;
use App\Football\Data\OriginStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A player's statistics for one season in one scope (league or Champions League).
 *
 * Every value records the provider that supplied it, and every provider records whether
 * it can be trusted for this player (ok / partial / suspect / missing) and on how many
 * minutes its numbers are based. A missing value is absent, never zero.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(columns: ['player_season_id', 'scope'])]
class PlayerCompetitionStats
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * @param array<string, float>                                                   $values       field key => value (known values only)
     * @param array<string, string>                                                  $valueOrigins field key => Origin value
     * @param array<string, array{status: string, minutes: ?float, reason: ?string}> $originChecks Origin value => check
     */
    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'stats')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private PlayerSeason $playerSeason,
        #[ORM\Column(length: 10, enumType: StatsScope::class)]
        private StatsScope $scope,
        /** The import batch these numbers come from. */
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private ImportBatch $batch,
        /** Reference minutes for this scope (FBref for leagues, SofaScore for the CL). */
        #[ORM\Column]
        private float $minutes,
        #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
        private array $values,
        #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
        private array $valueOrigins,
        #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
        private array $originChecks,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPlayerSeason(): PlayerSeason
    {
        return $this->playerSeason;
    }

    public function getScope(): StatsScope
    {
        return $this->scope;
    }

    public function getBatch(): ImportBatch
    {
        return $this->batch;
    }

    public function getMinutes(): float
    {
        return $this->minutes;
    }

    /** @return array<string, float> */
    public function getValues(): array
    {
        return $this->values;
    }

    public function value(string $field): ?float
    {
        return isset($this->values[$field]) ? (float) $this->values[$field] : null;
    }

    /** The provider that supplied this value. */
    public function origin(string $field): ?Origin
    {
        return isset($this->valueOrigins[$field]) ? Origin::from($this->valueOrigins[$field]) : null;
    }

    public function originStatus(Origin $origin): OriginStatus
    {
        return isset($this->originChecks[$origin->value])
            ? OriginStatus::from($this->originChecks[$origin->value]['status'])
            : OriginStatus::Missing;
    }

    /** Minutes a provider's numbers are based on: the right denominator for its per-90s. */
    public function originMinutes(Origin $origin): ?float
    {
        $minutes = $this->originChecks[$origin->value]['minutes'] ?? null;

        return $minutes !== null ? (float) $minutes : null;
    }

    public function originReason(Origin $origin): ?string
    {
        return $this->originChecks[$origin->value]['reason'] ?? null;
    }

    /** @return array<string, array{status: string, minutes: ?float, reason: ?string}> */
    public function getOriginChecks(): array
    {
        return $this->originChecks;
    }

    /** Trust status of a field: its provider's status, or the weakest input of a derived field. */
    public function fieldStatus(string $field): OriginStatus
    {
        $origin = $this->origin($field);
        $dependsOn = $origin !== null && $origin !== Origin::Derived ? [$origin] : FieldCatalog::get($field)->dependsOn();

        return OriginStatus::weakest(array_map($this->originStatus(...), $dependsOn));
    }

    /** Value exists and its provider covers the whole scope. */
    public function trusted(string $field): bool
    {
        return $this->value($field) !== null && $this->fieldStatus($field) === OriginStatus::Ok;
    }

    /**
     * Per-90 rate on the supplying provider's own minutes. Null when the value is unknown,
     * the provider is not usable (suspect/missing), or it has no minutes.
     */
    public function per90(string $field): ?float
    {
        $value = $this->value($field);
        $origin = $this->origin($field);
        if ($value === null || $origin === null || !$this->fieldStatus($field)->usable()) {
            return null;
        }
        $minutes = $origin === Origin::Derived ? $this->minutes : $this->originMinutes($origin);

        return $minutes !== null && $minutes > 0 ? $value / $minutes * 90 : null;
    }
}
