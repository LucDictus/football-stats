<?php

declare(strict_types=1);

namespace App\Football\Scoring;

/**
 * How raw metric values become comparable 0–100 scores within a comparison group.
 *
 * - Percentile: rank-based. Robust and fair across roles (every role gets the same spread),
 *   but flat at the top: the best is ~99 whether he leads by a little or by a lot.
 * - Standard: T-score, 50 + 10 × standard deviations from the group mean, clamped to 0–100.
 *   Keeps distances (a runaway leader stands out), but depends on each metric's shape.
 */
enum Normalization: string
{
    case Percentile = 'percentile';
    case Standard = 'standard';

    /**
     * @param array<array-key, float|null> $values    nulls stay null and are not counted
     * @param list<array-key>|null         $reference keys of the comparison group whose distribution is used (default: all)
     * @param float|null                   $zCap      standard scores: deviations beyond ±zCap count as ±zCap
     * @param bool                         $clamp     keep the result within 0–100
     *
     * @return array<array-key, float|null> same keys, higher is better
     */
    public function scores(array $values, bool $lowerIsBetter = false, ?array $reference = null, ?float $zCap = null, bool $clamp = true): array
    {
        if ($lowerIsBetter) {
            $values = array_map(static fn (?float $v) => $v === null ? null : -$v, $values);
        }

        return match ($this) {
            self::Percentile => Percentile::ranks($values, $reference),
            self::Standard => self::tScores($values, $reference, $zCap, $clamp),
        };
    }

    /**
     * @param array<array-key, float|null> $values
     * @param list<array-key>|null         $reference
     *
     * @return array<array-key, float|null>
     */
    private static function tScores(array $values, ?array $reference, ?float $zCap, bool $clamp): array
    {
        $group = $reference === null ? $values : array_intersect_key($values, array_flip($reference));
        $known = array_values(array_filter($group, static fn (?float $v) => $v !== null));
        if ($known === []) {
            $known = array_values(array_filter($values, static fn (?float $v) => $v !== null));
        }
        $n = count($known);
        $mean = $n > 0 ? array_sum($known) / $n : 0.0;
        $sd = $n > 1 ? sqrt(array_sum(array_map(static fn (float $v) => ($v - $mean) ** 2, $known)) / $n) : 0.0;

        return array_map(static function (?float $v) use ($mean, $sd, $zCap, $clamp): ?float {
            if ($v === null) {
                return null;
            }
            $z = $sd > 0 ? ($v - $mean) / $sd : 0.0;
            if ($zCap !== null) {
                $z = max(-$zCap, min($zCap, $z));
            }
            $t = 50 + 10 * $z;

            return $clamp ? max(0.0, min(100.0, $t)) : $t;
        }, $values);
    }
}
