<?php

declare(strict_types=1);

namespace App\Football\Data;

/** The original provider of a statistic. Each provider has its own minutes and its own player matching. */
enum Origin: string
{
    case Fbref = 'FBref';
    case Understat = 'Understat';
    case Sofascore = 'SofaScore';
    case Derived = 'derived';

    /** Column in the Top-5 dataset with the minutes this provider's numbers are based on. */
    public function leagueMinutesColumn(): ?string
    {
        return match ($this) {
            self::Fbref => 'playing_time_min',
            self::Understat => 'us_minutes',
            self::Sofascore => 'ss_minutesPlayed',
            self::Derived => null,
        };
    }

    /** @return list<self> providers with their own data (not derived) */
    public static function providers(): array
    {
        return [self::Fbref, self::Understat, self::Sofascore];
    }
}
