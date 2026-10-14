<?php

declare(strict_types=1);

namespace App\DataQuality;

use App\Football\Data\OriginCheck;

use App\Football\Data\OriginStatus;

use App\Football\Data\FieldCatalog;
use App\Football\Data\Origin;

/** One player's league season after merging club stints, with a trust check per provider. */
final readonly class MergedPlayerSeason
{
    /**
     * @param list<string>               $teams
     * @param list<string>               $leagues  league names as in the source
     * @param array<string, float|null>  $values   field key => value (null = unknown)
     * @param array<string, OriginCheck> $checks   Origin value => check
     * @param list<array{team: string, league: string, appearances: ?int, starts: ?int, minutes: ?int}> $stintDetails most minutes first
     */
    public function __construct(
        public string $key,
        public string $name,
        public ?int $born,
        public ?string $position,
        public bool $goalkeeper,
        public array $teams,
        public array $leagues,
        public ?int $sofascoreId,
        public int $stints,
        public float $minutes,
        public array $values,
        public array $checks,
        public array $stintDetails = [],
    ) {
    }

    public function check(Origin $origin): OriginCheck
    {
        return $this->checks[$origin->value] ?? new OriginCheck(OriginStatus::Ok, $this->minutes);
    }

    public function value(string $field): ?float
    {
        return $this->values[$field] ?? null;
    }

    /** True when the field has a value from a provider that covers the whole season. */
    public function trusted(string $field): bool
    {
        return $this->value($field) !== null && $this->fieldStatus($field) === OriginStatus::Ok;
    }

    /** Status of the field: its provider's status, or the weakest of its inputs for derived fields. */
    public function fieldStatus(string $field): OriginStatus
    {
        return OriginStatus::weakest(array_map(
            fn (Origin $o) => $this->check($o)->status,
            FieldCatalog::get($field)->dependsOn(),
        ));
    }
}
