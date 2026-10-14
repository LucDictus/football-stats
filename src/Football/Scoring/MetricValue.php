<?php

declare(strict_types=1);

namespace App\Football\Scoring;

use App\Entity\PlayerCompetitionStats;

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
            };
            if ($value === null) {
                return null; // one unknown part makes the metric unknown, never a partial sum
            }
            $sum += $value;
        }

        return $sum;
    }
}
