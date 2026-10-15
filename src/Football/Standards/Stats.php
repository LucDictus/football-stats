<?php

declare(strict_types=1);

namespace App\Football\Standards;

/** Small descriptive statistics for the standards (population moments, Pearson correlation). */
final class Stats
{
    /** @param array<array-key, float> $values */
    public static function mean(array $values): float
    {
        return $values === [] ? 0.0 : array_sum($values) / count($values);
    }

    /** @param array<array-key, float> $values */
    public static function variance(array $values): float
    {
        $mean = self::mean($values);

        return $values === [] ? 0.0 : array_sum(array_map(static fn (float $v) => ($v - $mean) ** 2, $values)) / count($values);
    }

    /**
     * Standard scores, keys kept.
     *
     * @template K of array-key
     *
     * @param array<K, float> $values
     *
     * @return array<K, float>
     */
    public static function standardise(array $values): array
    {
        $mean = self::mean($values);
        $sd = sqrt(self::variance($values));

        return array_map(static fn (float $v) => $sd > 0 ? ($v - $mean) / $sd : 0.0, $values);
    }

    /**
     * @param list<float> $x
     * @param list<float> $y
     */
    public static function correlation(array $x, array $y): ?float
    {
        $n = count($x);
        if ($n < 3 || $n !== count($y)) {
            return null;
        }
        $mx = self::mean($x);
        $my = self::mean($y);
        $sxy = 0.0;
        $sxx = 0.0;
        $syy = 0.0;
        foreach ($x as $i => $v) {
            $sxy += ($v - $mx) * ($y[$i] - $my);
            $sxx += ($v - $mx) ** 2;
            $syy += ($y[$i] - $my) ** 2;
        }

        return $sxx > 0 && $syy > 0 ? $sxy / sqrt($sxx * $syy) : null;
    }

    /**
     * Least-squares slope of y on x, and the mean of x.
     *
     * @param list<float> $x
     * @param list<float> $y
     *
     * @return array{0: float, 1: float}
     */
    public static function slope(array $x, array $y): array
    {
        $mx = self::mean($x);
        $my = self::mean($y);
        $sxy = 0.0;
        $sxx = 0.0;
        foreach ($x as $i => $v) {
            $sxy += ($v - $mx) * ($y[$i] - $my);
            $sxx += ($v - $mx) ** 2;
        }

        return [$sxx > 0 ? $sxy / $sxx : 0.0, $mx];
    }

    /**
     * y minus its least-squares fit on x.
     *
     * @param list<float> $y
     * @param list<float> $x
     *
     * @return list<float>
     */
    public static function residuals(array $y, array $x): array
    {
        [$b, $mx] = self::slope($x, $y);
        $my = self::mean($y);

        return array_map(static fn (float $v, float $u) => $v - $my - $b * ($u - $mx), $y, $x);
    }

    /**
     * Average-linkage clustering: repeatedly joins the two groups whose members correlate most
     * on average, while that average is at least $threshold. Deterministic (ties: first pair).
     *
     * @param array<string, array<int, float>> $series name => values by observation id
     *
     * @return list<list<string>> groups, each in the order of $series
     */
    public static function cluster(array $series, float $threshold): array
    {
        $names = array_keys($series);
        $r = [];
        foreach ($names as $a) {
            foreach ($names as $b) {
                if ($a < $b || !isset($r[$b][$a])) {
                    $common = array_keys(array_intersect_key($series[$a], $series[$b]));
                    $value = self::correlation(array_map(static fn (int $id) => $series[$a][$id], $common), array_map(static fn (int $id) => $series[$b][$id], $common)) ?? 0.0;
                    $r[$a][$b] = $value;
                    $r[$b][$a] = $value;
                }
            }
        }

        $groups = array_map(static fn (string $name) => [$name], $names);
        while (count($groups) > 1) {
            $best = null;
            foreach ($groups as $i => $g) {
                foreach ($groups as $j => $h) {
                    if ($j <= $i) {
                        continue;
                    }
                    $sum = 0.0;
                    foreach ($g as $a) {
                        foreach ($h as $b) {
                            $sum += $r[$a][$b];
                        }
                    }
                    $average = $sum / (count($g) * count($h));
                    if ($best === null || $average > $best[0]) {
                        $best = [$average, $i, $j];
                    }
                }
            }
            if ($best === null || $best[0] < $threshold) {
                break;
            }
            $groups[$best[1]] = array_merge($groups[$best[1]], $groups[$best[2]]);
            unset($groups[$best[2]]);
            $groups = array_values($groups);
        }
        $order = array_flip($names);

        return array_map(static function (array $g) use ($order) {
            usort($g, static fn (string $a, string $b) => $order[$a] <=> $order[$b]);

            return $g;
        }, $groups);
    }

    /**
     * Intraclass correlation (one-way ANOVA estimator, clipped to 0–1): how much of the spread of
     * a value lies between groups rather than within them.
     *
     * @param list<float>  $values
     * @param list<string> $groups the group of each value
     */
    public static function intraclassCorrelation(array $values, array $groups): float
    {
        $byGroup = [];
        foreach ($values as $i => $v) {
            $byGroup[$groups[$i]][] = $v;
        }
        $n = count($values);
        $k = count($byGroup);
        if ($k < 2 || $n <= $k) {
            return 0.0;
        }
        $mean = self::mean($values);
        $between = 0.0;
        $within = 0.0;
        $sumSquares = 0.0;
        foreach ($byGroup as $members) {
            $m = self::mean($members);
            $between += count($members) * ($m - $mean) ** 2;
            foreach ($members as $v) {
                $within += ($v - $m) ** 2;
            }
            $sumSquares += count($members) ** 2;
        }
        $msBetween = $between / ($k - 1);
        $msWithin = $within / ($n - $k);
        $size = ($n - $sumSquares / $n) / ($k - 1);
        $denominator = $msBetween + ($size - 1) * $msWithin;

        return $denominator > 0 ? max(0.0, min(1.0, ($msBetween - $msWithin) / $denominator)) : 0.0;
    }
}
