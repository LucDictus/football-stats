<?php

declare(strict_types=1);

namespace App\Football\Scoring;

use App\Entity\PlayerCompetitionStats;
use App\Football\Data\Origin;

/** Calculates a metric for one player, or null when the data does not support it. */
final class MetricValue
{
    public static function of(Metric $metric, PlayerCompetitionStats $stats): ?float
    {
        if ($metric->mode === MetricMode::Ratio) {
            $successes = self::usableSum($stats, $metric->fields);
            $attempts = self::usableSum($stats, $metric->sampleFields);

            return $successes === null || $attempts === null || $attempts <= 0 ? null : $successes / $attempts;
        }
        $sum = 0.0;
        if ($metric->mode === MetricMode::Per90 && $metric->proxy !== [] && self::proxy($metric, $stats) !== null && self::primaryUnknown($metric, $stats)) {
            return self::proxy($metric, $stats)[0];
        }
        foreach ($metric->fields as $field) {
            $value = match ($metric->mode) {
                MetricMode::Total => $stats->trusted($field) ? $stats->value($field) : null,
                MetricMode::Per90 => $stats->per90($field),
                MetricMode::Rate => $stats->fieldStatus($field)->usable() ? $stats->value($field) : null,
            };
            if ($value === null) {
                return null; // one unknown part makes the metric unknown, never a partial sum
            }
            $sum += $value;
        }

        return $sum;
    }

    /**
     * How much evidence the value rests on, for shrinkage: matches played (per 90, on the
     * provider's own minutes; the smallest when fields come from several providers) or
     * attempts (rates). Null when unknown or not applicable (totals).
     */
    public static function sample(Metric $metric, PlayerCompetitionStats $stats): ?float
    {
        if ($metric->mode === MetricMode::Ratio) {
            return self::usableSum($stats, $metric->sampleFields);
        }
        if ($metric->mode === MetricMode::Rate) {
            $attempts = 0.0;
            foreach ($metric->sampleFields as $field) {
                $value = $stats->value($field);
                if ($value === null) {
                    return null;
                }
                $attempts += $value;
            }

            return $attempts;
        }
        if ($metric->mode === MetricMode::Per90 && $metric->proxy !== [] && self::primaryUnknown($metric, $stats)) {
            return self::proxy($metric, $stats)[1] ?? null;
        }
        if ($metric->mode === MetricMode::Per90) {
            $matches = null;
            foreach ($metric->fields as $field) {
                $origin = $stats->origin($field);
                $minutes = $origin === null || $origin === Origin::Derived ? $stats->getMinutes() : $stats->originMinutes($origin);
                if ($minutes === null) {
                    return null;
                }
                $matches = min($matches ?? INF, $minutes / 90);
            }

            return $matches;
        }

        return null;
    }

    private static function primaryUnknown(Metric $metric, PlayerCompetitionStats $stats): bool
    {
        foreach ($metric->fields as $field) {
            if ($stats->per90($field) === null) {
                return true;
            }
        }

        return false;
    }

    /**
     * The proxy estimate per 90 and its sample in matches; null when unknown.
     *
     * @return array{0: float, 1: float}|null
     */
    private static function proxy(Metric $metric, PlayerCompetitionStats $stats): ?array
    {
        $sum = 0.0;
        $matches = INF;
        foreach ($metric->proxy as [$field, $coefficient]) {
            $value = $stats->per90($field);
            $origin = $stats->origin($field);
            if ($value === null || $origin === null) {
                return null;
            }
            $sum += $coefficient * $value;
            $matches = min($matches, ($origin === Origin::Derived ? $stats->getMinutes() : (float) $stats->originMinutes($origin)) / 90);
        }

        return [max(0.0, $sum) * (float) $metric->proxyScale, $matches];
    }

    /**
     * Sum of fields from usable providers ("-field" subtracts); null when one is unknown.
     *
     * @param list<string> $fields
     */
    private static function usableSum(PlayerCompetitionStats $stats, array $fields): ?float
    {
        $sum = 0.0;
        foreach ($fields as $field) {
            $name = ltrim($field, '-');
            $value = $stats->fieldStatus($name)->usable() ? $stats->value($name) : null;
            if ($value === null) {
                return null;
            }
            $sum += str_starts_with($field, '-') ? -$value : $value;
        }

        return $sum;
    }
}
