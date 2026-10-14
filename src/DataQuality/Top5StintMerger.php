<?php

declare(strict_types=1);

namespace App\DataQuality;

use App\DataImport\Top5\Top5CsvReader;
use App\Football\Data\FieldCatalog;
use App\Football\Data\Origin;
use App\Football\Data\OriginCheck;
use App\Football\Data\OriginStatus;

/**
 * Turns Top-5 dataset stint rows (one per player per club) into one season per player.
 *
 * - FBref fields are per stint and are summed. One unknown stint makes the sum unknown.
 * - Understat and SofaScore fields are repeated on every stint row, so they are taken once.
 *   After a transfer between leagues they cover only one league: each provider's own
 *   minutes are kept and checked against FBref, so per-90s can use the right minutes.
 * - Name + birth year is the dataset's identity, and it sometimes merges two different
 *   players (two Vitinhas born in 2000). Such merges are split again, and provider records
 *   that cannot be attributed to one of them are marked suspect.
 */
final class Top5StintMerger
{
    /** More league appearances than a 38-match season plus a margin for cross-league schedules. */
    public const MAX_SEASON_APPEARANCES = 42;

    /**
     * @param iterable<array<string, string>> $rows raw CSV rows
     *
     * @return list<MergedPlayerSeason>
     */
    public function merge(iterable $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups[Top5CsvReader::playerKey($row)][] = $row;
        }

        // Split groups that cannot be one player.
        $conflicts = [];
        foreach ($groups as $key => $stints) {
            if (count($stints) > 1 && array_sum(array_map(static fn ($s) => (float) self::num($s['playing_time_mp']), $stints)) > self::MAX_SEASON_APPEARANCES) {
                unset($groups[$key]);
                $teams = implode(' and ', array_column($stints, 'team'));
                foreach ($stints as $stint) {
                    $groups[$key . '|' . $stint['team']] = [$stint];
                    $conflicts[$key . '|' . $stint['team']] = "name and birth year shared by players at {$teams}";
                }
            }
        }

        // A SofaScore id on more than one player cannot be attributed to either of them.
        $idCount = array_count_values(array_filter(array_map(
            static fn (array $stints) => (string) (self::num($stints[0]['ss_player_id'] ?? '') ?? ''),
            $groups
        )));

        $players = [];
        foreach ($groups as $key => $stints) {
            $sharedId = ($idCount[(string) (self::num($stints[0]['ss_player_id'] ?? '') ?? '')] ?? 0) > 1;
            $players[] = $this->mergePlayer((string) $key, $stints, $conflicts[$key] ?? null, $sharedId);
        }

        return $players;
    }

    /**
     * @param list<array<string, string>> $stints
     * @param string|null                 $identityConflict  why the providers' records cannot be attributed
     * @param bool                        $sharedSofascoreId the SofaScore id also belongs to another player
     */
    public function mergePlayer(string $key, array $stints, ?string $identityConflict = null, bool $sharedSofascoreId = false): MergedPlayerSeason
    {
        usort($stints, static fn (array $a, array $b) => self::num($b['playing_time_min']) <=> self::num($a['playing_time_min']));
        $main = $stints[0];
        $goalkeeper = str_contains($main['pos'], 'GK');

        $values = [];
        foreach (FieldCatalog::all() as $field) {
            if ($field->leagueColumn === null) {
                continue;
            }
            $values[$field->key] = $field->origin === Origin::Fbref
                ? self::sum($stints, $field->leagueColumn)
                : self::num($main[$field->leagueColumn]);
        }

        $stintMinutes = array_map(static fn (array $s) => (float) self::num($s['playing_time_min']), $stints);
        $minutes = (float) array_sum($stintMinutes);
        $checks = [Origin::Fbref->value => new OriginCheck(OriginStatus::Ok, $minutes)];
        foreach ([Origin::Understat, Origin::Sofascore] as $origin) {
            $checks[$origin->value] = OriginConsistency::check(
                self::num($main[$origin->leagueMinutesColumn()]),
                $minutes,
                $stintMinutes,
                $origin === Origin::Sofascore ? self::num($main['ss_goals']) : null,
                $origin === Origin::Sofascore ? $values['goals'] : null,
            );
            if ($identityConflict !== null && $checks[$origin->value]->status !== OriginStatus::Missing) {
                $checks[$origin->value] = new OriginCheck(OriginStatus::Suspect, $checks[$origin->value]->minutes, $identityConflict);
            }
        }
        if ($sharedSofascoreId && $checks[Origin::Sofascore->value]->status !== OriginStatus::Missing) {
            $checks[Origin::Sofascore->value] = new OriginCheck(OriginStatus::Suspect, $checks[Origin::Sofascore->value]->minutes, 'SofaScore id shared with another player');
        }

        // Derived fields.
        // Season goals (FBref) minus season penalty goals (SofaScore): only when SofaScore covers the whole season.
        $values['np_goals'] = $values['goals'] !== null && $values['penalty_goals'] !== null
            && $checks[Origin::Sofascore->value]->status === OriginStatus::Ok
            ? $values['goals'] - $values['penalty_goals']
            : null;
        $values['save_pct'] = $goalkeeper && $values['saves'] !== null && $values['goals_conceded'] !== null
            && $values['saves'] + $values['goals_conceded'] > 0
            ? round($values['saves'] / ($values['saves'] + $values['goals_conceded']) * 100, 1)
            : null;
        if (!$goalkeeper) {
            // "Goals conceded" etc. for outfield players means "while on the pitch": not a goalkeeping stat.
            foreach (FieldCatalog::all() as $field) {
                if ($field->goalkeeper) {
                    $values[$field->key] = null;
                }
            }
        }

        return new MergedPlayerSeason(
            $key,
            $main['player'],
            self::num($main['born']) !== null ? (int) self::num($main['born']) : null,
            $main['pos'] ?: null,
            $goalkeeper,
            array_values(array_unique(array_column($stints, 'team'))),
            array_values(array_unique(array_column($stints, 'league'))),
            self::num($main['ss_player_id'] ?? '') !== null && !$sharedSofascoreId ? (int) self::num($main['ss_player_id']) : null,
            count($stints),
            $minutes,
            $values,
            $checks,
            array_map(static fn (array $s) => [
                'team' => $s['team'],
                'league' => $s['league'],
                'appearances' => self::int($s['playing_time_mp']),
                'starts' => self::int($s['playing_time_starts']),
                'minutes' => self::int($s['playing_time_min']),
            ], $stints),
        );
    }

    private static function int(?string $value): ?int
    {
        $number = self::num($value);

        return $number !== null ? (int) $number : null;
    }

    /** @param list<array<string, string>> $stints */
    private static function sum(array $stints, string $column): ?float
    {
        $sum = 0.0;
        foreach ($stints as $stint) {
            $value = self::num($stint[$column]);
            if ($value === null) {
                return null;
            }
            $sum += $value;
        }

        return $sum;
    }

    private static function num(?string $value): ?float
    {
        return $value !== null && $value !== '' && is_numeric($value) ? (float) $value : null;
    }
}
