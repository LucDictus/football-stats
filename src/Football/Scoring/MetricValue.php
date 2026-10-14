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
        $sum = 0.0;
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
     * provider's own minutes; the smallest when fields come from several providers), or the
     * sum of the metric's sample fields (attempts for rates, or events such as shots on target
     * faced). Null when unknown or not applicable (totals).
     */
    public static function sample(Metric $metric, PlayerCompetitionStats $stats): ?float
    {
        if ($metric->mode === MetricMode::Rate || ($metric->mode === MetricMode::Per90 && $metric->sampleFields !== [])) {
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
}
