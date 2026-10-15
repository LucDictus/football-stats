<?php

declare(strict_types=1);

namespace App\Football\Standards;

use App\Entity\PlayerCompetitionStats;
use App\Football\Data\FieldCatalog;
use App\Football\Data\Origin;

/**
 * A candidate statistic for the positional standards: a count per 90 minutes, or a success
 * rate (successes / attempts). Its direction comes from its definition: a success counts in a
 * player's favour (+1), a failure against him (−1).
 */
final readonly class Statistic
{
    public const PER90 = 'per90';
    public const RATE = 'rate';

    /**
     * @param non-empty-list<string>           $fields   fields summed for the count, or the successes of a rate
     * @param list<string>                     $attempts rates: fields summed for the attempts ("-field" subtracts)
     * @param list<array{0: string, 1: float}> $proxy    counts: for players the provider has no record of, an
     *                                                   estimate from another provider (field, coefficient),
     *                                                   scaled to the primary statistic per role (derived)
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $kind,
        public array $fields,
        public int $direction,
        public array $attempts = [],
        public ?string $note = null,
        public array $proxy = [],
    ) {
        if ($kind === self::RATE && $attempts === []) {
            throw new \InvalidArgumentException("Statistic {$key}: a rate needs attempts");
        }
    }

    /**
     * The raw value for a player: per 90 minutes of the provider's own minutes, or the success
     * rate; with the sample it rests on (matches, or attempts). Null when unknown or when the
     * provider covers too few minutes.
     *
     * @param float|null $proxyScale scale of the proxy estimate; null: no proxy
     *
     * @return array{0: float, 1: float}|null value, sample
     */
    public function measure(PlayerCompetitionStats $stats, ?float $proxyScale = null): ?array
    {
        if ($this->kind === self::PER90) {
            $primary = $this->per90($stats);

            return $primary ?? ($proxyScale !== null ? $this->proxyPer90($stats, $proxyScale) : null);
        }

        $successes = self::sum($stats, $this->fields);
        $attempts = self::sum($stats, $this->attempts);

        return $successes === null || $attempts === null || $attempts <= 0 ? null : [$successes / $attempts, $attempts];
    }

    /**
     * The proxy estimate per 90 before scaling (scale 1), or scaled; null when unknown.
     *
     * @return array{0: float, 1: float}|null value, sample in matches
     */
    public function proxyPer90(PlayerCompetitionStats $stats, float $scale = 1.0): ?array
    {
        if ($this->proxy === []) {
            return null;
        }
        $sum = 0.0;
        $matches = INF;
        foreach ($this->proxy as [$field, $coefficient]) {
            $value = $stats->per90($field);
            if ($value === null) {
                return null;
            }
            $sum += $coefficient * $value;
            $origin = $stats->origin($field);
            $matches = min($matches, ($origin === null || $origin === Origin::Derived ? $stats->getMinutes() : (float) $stats->originMinutes($origin)) / 90);
        }

        return [max(0.0, $sum) * $scale, $matches];
    }

    /**
     * The statistic per 90 from its own provider.
     *
     * @return array{0: float, 1: float}|null value, sample in matches
     */
    public function per90(PlayerCompetitionStats $stats): ?array
    {
        $sum = 0.0;
        $matches = INF;
        foreach ($this->fields as $field) {
            $value = $stats->per90($field);
            if ($value === null) {
                return null;
            }
            $sum += $value;
            $origin = $stats->origin($field);
            $minutes = $origin === null || $origin === Origin::Derived ? $stats->getMinutes() : (float) $stats->originMinutes($origin);
            $matches = min($matches, $minutes / 90);
        }

        return [$sum, $matches];
    }

    /** Which providers the statistic depends on, for its source column. */
    public function source(): string
    {
        $origins = [];
        foreach ([...$this->fields, ...array_map(static fn (string $f) => ltrim($f, '-'), $this->attempts)] as $field) {
            foreach (FieldCatalog::get($field)->dependsOn() as $origin) {
                $origins[$origin->value] = true;
            }
        }

        return implode(', ', array_keys($origins));
    }

    /** @param list<string> $fields */
    private static function sum(PlayerCompetitionStats $stats, array $fields): ?float
    {
        $sum = 0.0;
        foreach ($fields as $field) {
            $name = ltrim($field, '-');
            if (!$stats->fieldStatus($name)->usable()) {
                return null;
            }
            $value = $stats->value($name);
            if ($value === null) {
                return null;
            }
            $sum += str_starts_with($field, '-') ? -$value : $value;
        }

        return $sum;
    }
}
