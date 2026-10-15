<?php

declare(strict_types=1);

namespace App\Tests\Unit\DataImport;

use App\DataImport\Top5\Top5TeamResults;
use PHPUnit\Framework\TestCase;

final class Top5TeamResultsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/top5-results-' . bin2hex(random_bytes(4));
        mkdir($this->directory . '/data/schedule', 0o777, true);
        file_put_contents($this->directory . '/data/schedule/matches.csv', implode("\n", [
            'league,season,game,week,day,date,time,home_team,score,away_team,attendance,venue,referee,match_report,notes,round,game_id',
            'FRA-Ligue 1,2526,g1,1,Sat,2025-08-16,,Paris FC,2–0,Lyon,,,,,,,a',
            'FRA-Ligue 1,2526,g2,2,Sat,2025-08-23,,Lyon,1–1,Paris FC,,,,,,,b',
            'FRA-Ligue 1,2526,g3,3,Sat,2025-08-30,,Paris FC,,Lyon,,,,,,,c',
            'FRA-Ligue 1,2526,g4,,Thu,2026-05-28,,Lyon,3–0,Paris FC,,,,,Leg 1 of 2,French 1/2 Relegation/Promotion play-offs,d',
            'FRA-Ligue 1,2425,g5,1,Sat,2024-08-16,,Lyon,5–0,Paris FC,,,,,,,e',
        ]) . "\n");
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->directory . '/data/schedule/*') ?: []);
        rmdir($this->directory . '/data/schedule');
        rmdir($this->directory . '/data');
        rmdir($this->directory);
    }

    public function testGoalDifferencePerPlayedLeagueMatch(): void
    {
        $results = (new Top5TeamResults($this->directory))->goalDifference('2526');

        // Two played matches (2–0, 1–1); the unplayed one and the play-off are not league matches.
        self::assertSame(['goal_difference' => 1.0, 'matches' => 2], $results['FRA-Ligue 1|Paris']);
        self::assertSame(['goal_difference' => -1.0, 'matches' => 2], $results['FRA-Ligue 1|Lyon']);
        self::assertArrayNotHasKey('FRA-Ligue 1|Paris FC', $results, 'named as in the player data');
    }

    public function testPlayOffRoundsAreRecognised(): void
    {
        self::assertTrue(Top5TeamResults::playOff('German 1/2 Relegation/Promotion play-offs'));
        self::assertTrue(Top5TeamResults::playOff('Semi-finals'));
        self::assertTrue(Top5TeamResults::playOff('First Round'));
        self::assertFalse(Top5TeamResults::playOff(''));
        self::assertFalse(Top5TeamResults::playOff('Bundesliga'));
    }
}
