<?php

declare(strict_types=1);

namespace App\Tests\Unit\DataQuality;

use App\DataQuality\Top5StintMerger;
use App\Football\Data\Origin;
use App\Football\Data\OriginStatus;
use PHPUnit\Framework\TestCase;

final class Top5StintMergerTest extends TestCase
{
    public function testSingleClubSeason(): void
    {
        $player = $this->mergeOne([$this->row(['fb_gls' => '36', 'ss_goals' => '36', 'ss_penaltyGoals' => '10'])]);

        self::assertSame(36.0, $player->value('goals'));
        self::assertSame(26.0, $player->value('np_goals'));
        self::assertSame(1, $player->stints);
        self::assertTrue($player->trusted('np_goals'));
        self::assertSame(123456, $player->sofascoreId);
    }

    public function testFbrefStintsAreSummedAndProviderSeasonValuesTakenOnce(): void
    {
        // Same-league transfer: Understat/SofaScore repeat their season totals on both rows.
        $player = $this->mergeOne([
            $this->row(['team' => 'Arsenal', 'playing_time_min' => '1812', 'fb_gls' => '7']),
            $this->row(['team' => 'Crystal Palace', 'playing_time_min' => '83', 'fb_gls' => '0']),
        ], usMinutes: '1928', ssMinutes: '1900');

        self::assertSame(1895.0, $player->minutes);
        self::assertSame(7.0, $player->value('goals'));
        self::assertSame(40.0, $player->value('tackles'), 'taken once, not 80');
        self::assertSame(OriginStatus::Ok, $player->check(Origin::Sofascore)->status);
        self::assertSame(['Arsenal', 'Crystal Palace'], $player->teams);
    }

    public function testTransferBetweenLeaguesMarksProviderPartial(): void
    {
        $player = $this->mergeOne([
            $this->row(['team' => 'Arsenal', 'league' => 'ENG-Premier League', 'playing_time_min' => '1792']),
            $this->row(['team' => 'Leverkusen', 'league' => 'GER-Bundesliga', 'playing_time_min' => '90']),
        ], usMinutes: '1786', ssMinutes: '90');

        self::assertSame(OriginStatus::Partial, $player->check(Origin::Sofascore)->status);
        self::assertSame(90.0, $player->check(Origin::Sofascore)->minutes, 'per-90s must use these minutes');
        self::assertSame(OriginStatus::Ok, $player->check(Origin::Understat)->status);
        self::assertFalse($player->trusted('tackles'));
        self::assertTrue($player->trusted('xg'));
        self::assertNull($player->value('np_goals'), 'full-season goals minus one club\'s penalties would be wrong');
    }

    public function testUnknownStintMakesSumUnknown(): void
    {
        $player = $this->mergeOne([
            $this->row(['team' => 'A', 'fb_sh' => '10']),
            $this->row(['team' => 'B', 'fb_sh' => '']),
        ]);

        self::assertNull($player->value('shots'));
    }

    public function testMissingProviderStaysNull(): void
    {
        $player = $this->mergeOne([$this->row()], usMinutes: '', usXg: '');

        self::assertNull($player->value('xg'));
        self::assertSame(OriginStatus::Missing, $player->check(Origin::Understat)->status);
        self::assertFalse($player->trusted('xg'));
    }

    public function testGoalkeeperFieldsOnlyForGoalkeepers(): void
    {
        $outfield = $this->mergeOne([$this->row(['ss_saves' => '0', 'ss_goalsConceded' => '30'])]);
        $keeper = $this->mergeOne([$this->row(['pos' => 'GK', 'ss_saves' => '90', 'ss_goalsConceded' => '30'])]);

        self::assertNull($outfield->value('goals_conceded'));
        self::assertNull($outfield->value('save_pct'));
        self::assertSame(75.0, $keeper->value('save_pct'));
    }

    public function testSameNameDifferentBirthYearAreDifferentPlayers(): void
    {
        $players = (new Top5StintMerger())->merge([
            $this->row(['player' => 'Wesley', 'born' => '2003', 'team' => 'Roma']),
            $this->row(['player' => 'Wesley', 'born' => '2005', 'team' => 'Other']),
        ]);

        self::assertCount(2, $players);
    }

    public function testTwoPlayersWithTheSameNameAndBirthYearAreSplit(): void
    {
        // Two Vitinhas born in 2000: 64 league appearances is not one player's season.
        $players = (new Top5StintMerger())->merge([
            $this->row(['player' => 'Vitinha', 'born' => '2000', 'team' => 'PSG', 'playing_time_mp' => '33', 'playing_time_min' => '2119', 'ss_minutesPlayed' => '2254']),
            $this->row(['player' => 'Vitinha', 'born' => '2000', 'team' => 'Genoa', 'playing_time_mp' => '31', 'playing_time_min' => '2226', 'ss_minutesPlayed' => '2254']),
        ]);

        self::assertCount(2, $players);
        self::assertSame(['PSG'], $players[0]->teams);
        self::assertSame(2119.0, $players[0]->minutes);
        self::assertSame(OriginStatus::Suspect, $players[0]->check(Origin::Sofascore)->status);
        self::assertStringContainsString('PSG and Genoa', (string) $players[1]->check(Origin::Understat)->reason);
    }

    public function testRealTransferIsNotSplit(): void
    {
        $players = (new Top5StintMerger())->merge([
            $this->row(['team' => 'Aston Villa', 'playing_time_mp' => '20']),
            $this->row(['team' => 'Roma', 'playing_time_mp' => '19']),
        ]);

        self::assertCount(1, $players);
    }

    public function testSharedSofascoreIdIsNotTrustedOrLinked(): void
    {
        $players = (new Top5StintMerger())->merge([
            $this->row(['player' => 'Wesley', 'born' => '2003', 'team' => 'Roma', 'ss_player_id' => '1211632.0']),
            $this->row(['player' => 'Wesley', 'born' => '2005', 'team' => 'Real Sociedad', 'ss_player_id' => '1211632.0']),
        ]);

        foreach ($players as $player) {
            self::assertSame(OriginStatus::Suspect, $player->check(Origin::Sofascore)->status);
            self::assertNull($player->sofascoreId);
        }
    }

    /** @param list<array<string, string>> $rows */
    private function mergeOne(array $rows, string $usMinutes = '2000', string $ssMinutes = '2000', string $usXg = '5.5'): \App\DataQuality\MergedPlayerSeason
    {
        $rows = array_map(static fn (array $r) => ['us_minutes' => $usMinutes, 'ss_minutesPlayed' => $ssMinutes, 'us_xg' => $usXg] + $r, $rows);
        $players = (new Top5StintMerger())->merge($rows);
        self::assertCount(1, $players);

        return $players[0];
    }

    /**
     * A stint row with every catalog column present. mergeOne() overrides the provider minutes.
     *
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function row(array $overrides = []): array
    {
        $row = [
            'player' => 'Test Player', 'born' => '1995', 'team' => 'Club', 'league' => 'ENG-Premier League',
            'pos' => 'FW', 'ss_player_id' => '123456.0', 'ss_goals' => '',
            'us_minutes' => '2000', 'ss_minutesPlayed' => '2000', 'us_xg' => '5.5',
            'playing_time_min' => '2000', 'playing_time_mp' => '15', 'playing_time_starts' => '12',
            'fb_gls' => '5', 'fb_ast' => '3', 'fb_sh' => '40', 'fb_sot' => '15',
            'us_np_xg' => '4.5', 'us_xa' => '2.5', 'us_xg_chain' => '8.0', 'us_xg_buildup' => '3.0',
        ];
        foreach (['ss_penaltyGoals', 'ss_keyPasses', 'ss_bigChancesCreated', 'ss_accurateFinalThirdPasses', 'ss_accuratePasses',
            'ss_accuratePassesPercentage', 'ss_tackles', 'ss_tacklesWon', 'ss_interceptions', 'ss_outfielderBlocks', 'ss_clearances',
            'ss_ballRecovery', 'ss_aerialDuelsWon', 'ss_aerialLost', 'ss_errorLeadToGoal', 'ss_saves', 'ss_goalsConceded',
            'ss_goalsPrevented', 'ss_cleanSheet'] as $column) {
            $row[$column] = $column === 'ss_tackles' ? '40' : '1';
        }

        return $overrides + $row;
    }
}
