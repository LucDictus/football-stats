<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Football\Data\FieldCatalog;

/** Raw source records as the importers store them, with sensible defaults. */
final class SourceFixtures
{
    /**
     * A Top-5 dataset stint row with every column the pipeline reads.
     *
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    public static function top5Row(array $overrides = []): array
    {
        $row = [
            'player' => 'Test Player', 'born' => '1995', 'team' => 'Club', 'league' => 'ENG-Premier League',
            'pos' => 'FW', 'ss_player_id' => '', 'ss_goals' => '', 'season' => '2526',
            'us_minutes' => '2000', 'ss_minutesPlayed' => '2000', 'us_np_goals' => '',
        ];
        foreach (FieldCatalog::all() as $field) {
            if ($field->leagueColumn !== null) {
                $row[$field->leagueColumn] = '1';
            }
        }
        $row['playing_time_min'] = '2000';
        $row['playing_time_mp'] = '25';

        return $overrides + $row;
    }

    /**
     * A SofaScore season statistics record.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function sofascoreRecord(int $playerId, string $name, int $teamId, string $team, array $overrides = []): array
    {
        $record = ['player' => ['id' => $playerId, 'name' => $name], 'team' => ['id' => $teamId, 'name' => $team]];
        foreach (FieldCatalog::all() as $field) {
            if ($field->clColumn !== null) {
                $record[$field->clColumn] = 1;
            }
        }
        $record['minutesPlayed'] = 600;
        $record['goalsPrevented'] = null;
        $record['saves'] = 0;

        return $overrides + $record;
    }
}
