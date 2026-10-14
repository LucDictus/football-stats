<?php

declare(strict_types=1);

namespace App\DataQuality;

use App\Football\Data\OriginCheck;
use App\Football\Data\OriginStatus;

/**
 * Compares a provider's minutes (and goals) with FBref, the reference for playing time.
 *
 * Tolerance: 15% of FBref minutes, at least 30 minutes (providers count stoppage time
 * differently, and small samples swing a lot in relative terms).
 */
final class OriginConsistency
{
    public const MINUTES_TOLERANCE = 0.15;
    public const MINUTES_TOLERANCE_ABSOLUTE = 30.0;
    public const GOALS_TOLERANCE = 1;

    /**
     * @param list<float> $stintMinutes FBref minutes per club stint
     */
    public static function check(
        ?float $originMinutes,
        float $fbrefMinutes,
        array $stintMinutes,
        ?float $originGoals = null,
        ?float $fbrefGoals = null,
    ): OriginCheck {
        if ($originMinutes === null) {
            return new OriginCheck(OriginStatus::Missing, null, 'no record');
        }

        $tolerance = max(self::MINUTES_TOLERANCE * $fbrefMinutes, self::MINUTES_TOLERANCE_ABSOLUTE);
        $difference = $originMinutes - $fbrefMinutes;

        if (abs($difference) > $tolerance) {
            // Too few minutes after a transfer: the provider only has one of the clubs.
            if (count($stintMinutes) > 1 && $difference < 0) {
                return new OriginCheck(OriginStatus::Partial, $originMinutes, sprintf(
                    'covers %d of %d minutes%s',
                    $originMinutes,
                    $fbrefMinutes,
                    self::matchesOneStint($originMinutes, $stintMinutes) ? ' (one club stint)' : ''
                ));
            }

            return new OriginCheck(OriginStatus::Suspect, $originMinutes, sprintf('%d minutes vs %d in FBref', $originMinutes, $fbrefMinutes));
        }

        if ($originGoals !== null && $fbrefGoals !== null && abs($originGoals - $fbrefGoals) > self::GOALS_TOLERANCE) {
            return new OriginCheck(OriginStatus::Suspect, $originMinutes, sprintf('%d goals vs %d in FBref', $originGoals, $fbrefGoals));
        }

        return new OriginCheck(OriginStatus::Ok, $originMinutes);
    }

    /** @param list<float> $stintMinutes */
    private static function matchesOneStint(float $minutes, array $stintMinutes): bool
    {
        foreach ($stintMinutes as $stint) {
            if (abs($minutes - $stint) <= max(self::MINUTES_TOLERANCE * $stint, self::MINUTES_TOLERANCE_ABSOLUTE)) {
                return true;
            }
        }

        return false;
    }
}
