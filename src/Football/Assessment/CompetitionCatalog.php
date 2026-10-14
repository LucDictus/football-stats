<?php

declare(strict_types=1);

namespace App\Football\Assessment;

use App\Entity\Competition;

/** The competitions the project knows, and how each source names them. */
final class CompetitionCatalog
{
    /** code => [name, type, country] */
    public const COMPETITIONS = [
        'PL' => ['Premier League', Competition::TYPE_LEAGUE, 'ENG'],
        'PD' => ['La Liga', Competition::TYPE_LEAGUE, 'ESP'],
        'SA' => ['Serie A', Competition::TYPE_LEAGUE, 'ITA'],
        'BL1' => ['Bundesliga', Competition::TYPE_LEAGUE, 'GER'],
        'FL1' => ['Ligue 1', Competition::TYPE_LEAGUE, 'FRA'],
        'CL' => ['UEFA Champions League', Competition::TYPE_CUP, null],
    ];

    /** Matches per team in a league season. */
    public const LEAGUE_MATCHES = ['PL' => 38, 'PD' => 38, 'SA' => 38, 'BL1' => 34, 'FL1' => 34];

    /** League names in the Top-5 dataset => code */
    public const TOP5_LEAGUES = [
        'ENG-Premier League' => 'PL',
        'ESP-La Liga' => 'PD',
        'ITA-Serie A' => 'SA',
        'GER-Bundesliga' => 'BL1',
        'FRA-Ligue 1' => 'FL1',
    ];

    public static function codeForTop5League(string $league): string
    {
        return self::TOP5_LEAGUES[$league] ?? throw new \InvalidArgumentException("Unknown Top-5 dataset league {$league}");
    }
}
