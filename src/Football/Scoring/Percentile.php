<?php

declare(strict_types=1);

namespace App\Football\Scoring;

/**
 * Mid-rank percentiles: the share of the group below a value, plus half the share equal to it.
 * 0–100; the best player approaches 100, ties share a percentile, a lone value scores 50.
 */
final class Percentile
{
    /**
     * @param array<array-key, float|null> $values nulls are left out and stay null
     *
     * @return array<array-key, float|null> same keys
     */
    public static function ranks(array $values): array
    {
        $known = array_filter($values, static fn (?float $v) => $v !== null);
        $n = count($known);
        $sorted = array_values($known);
        sort($sorted);

        $result = [];
        foreach ($values as $key => $value) {
            if ($value === null) {
                $result[$key] = null;
                continue;
            }
            $below = self::countBelow($sorted, $value);
            $equal = self::countBelow($sorted, $value, true) - $below;
            $result[$key] = ($below + $equal / 2) / $n * 100;
        }

        return $result;
    }

    /** @param list<float> $sorted */
    private static function countBelow(array $sorted, float $value, bool $orEqual = false): int
    {
        $low = 0;
        $high = count($sorted);
        while ($low < $high) {
            $mid = intdiv($low + $high, 2);
            if ($sorted[$mid] < $value || ($orEqual && $sorted[$mid] == $value)) {
                $low = $mid + 1;
            } else {
                $high = $mid;
            }
        }

        return $low;
    }
}
