<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Imports data/master/player_seasons.csv from the Top-5 Football Dataset into
 * ext_player_seasons: one row per player per season, columns per config/fields.php.
 *
 * Rules:
 * - A player who changed clubs has one CSV row per club stint. 'stint' fields are
 *   summed over the stints; 'season' fields (Understat, SofaScore) are already season
 *   totals repeated on every stint row, so they are taken once.
 * - Empty values stay NULL. A stint sum with any NULL stint becomes NULL (unknown),
 *   never a partial total.
 */
final class Top5Importer
{
    public const SOURCE = 'top5';
    public const TABLE = 'ext_player_seasons';

    private const LEAGUE_CODES = [
        'ENG-Premier League' => 'PL',
        'ESP-La Liga' => 'PD',
        'ITA-Serie A' => 'SA',
        'GER-Bundesliga' => 'BL1',
        'FRA-Ligue 1' => 'FL1',
    ];

    /** @var array<string, array{column: ?string, level: string}> field => top5 source config */
    private array $fields = [];

    public function __construct(private readonly PDO $db, array $registry)
    {
        foreach ($registry['fields'] as $key => $field) {
            foreach ($field['sources'] as $source) {
                if (($source['source'] ?? null) === self::SOURCE) {
                    $this->fields[$key] = ['column' => $source['column'], 'level' => $source['level']];
                }
            }
        }
    }

    /**
     * @param string $seasonCode season value in the CSV, e.g. '2526' for 2025/26
     * @return array{rows: int, players: int, merged: int, conflicts: list<string>}
     */
    public function import(string $csvFile, string $seasonCode, int $season, ?string $version): array
    {
        $groups = $this->readGroups($csvFile, $seasonCode);
        $this->createTable();

        $columns = array_merge(
            ['source', 'season', 'player_key', 'player', 'born', 'nation', 'pos', 'is_goalkeeper', 'age',
             'teams', 'leagues', 'stints', 'sofascore_id'],
            array_keys($this->fields)
        );
        $insert = $this->db->prepare(sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            self::TABLE,
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?'))
        ));

        $conflicts = [];
        $rows = 0;
        $merged = 0;

        $this->db->beginTransaction();
        foreach ($groups as $key => $stints) {
            $main = $this->mainStint($stints);
            $values = [];

            foreach ($this->fields as $field => $cfg) {
                $values[$field] = match ($cfg['level']) {
                    'stint' => $this->sumStints($stints, $cfg['column']),
                    'season' => $this->seasonValue($stints, $main, $cfg['column'], $key, $conflicts),
                    default => null, // derived, filled below
                };
            }

            if (array_key_exists('save_pct', $values)) {
                // Only meaningful for goalkeepers: for outfield players these are "conceded while on the pitch".
                $values['save_pct'] = str_contains((string) $main['pos'], 'GK')
                    ? $this->savePct($values['saves'] ?? null, $values['goals_conceded'] ?? null)
                    : null;
            }

            $leagues = array_values(array_unique(array_map(
                static fn (array $s) => self::LEAGUE_CODES[$s['league']] ?? $s['league'],
                $stints
            )));

            $identity = [
                self::SOURCE,
                $season,
                $key,
                $main['player'],
                $this->num($main['born']),
                $main['nation'] ?: null,
                $main['pos'] ?: null,
                str_contains((string) $main['pos'], 'GK') ? 1 : 0,
                $this->num($main['age']),
                implode(', ', array_unique(array_column($stints, 'team'))),
                implode(',', $leagues),
                count($stints),
                $this->num($main['ss_player_id'] ?? ''),
            ];

            $insert->execute(array_merge($identity, array_values($values)));
            $rows++;
            $merged += count($stints) > 1 ? 1 : 0;
        }
        $this->db->commit();

        $this->recordImport($csvFile, $version, $rows);

        return ['rows' => array_sum(array_map('count', $groups)), 'players' => $rows, 'merged' => $merged, 'conflicts' => $conflicts];
    }

    /** @return array<string, list<array<string, string>>> player key => stint rows */
    private function readGroups(string $csvFile, string $seasonCode): array
    {
        $fh = fopen($csvFile, 'r');
        if ($fh === false) {
            throw new \RuntimeException("Cannot open {$csvFile}");
        }

        $header = fgetcsv($fh, escape: '');
        foreach ($this->fields as $field => $cfg) {
            if ($cfg['column'] !== null && !in_array($cfg['column'], $header, true)) {
                throw new \RuntimeException("Column {$cfg['column']} (field {$field}) not found in {$csvFile}");
            }
        }

        $groups = [];
        while (($line = fgetcsv($fh, escape: '')) !== false) {
            if ($line === [null]) {
                continue;
            }
            $row = array_combine($header, $line);
            if ($row['season'] !== $seasonCode) {
                continue;
            }
            // Player + birth year identifies a player in this dataset (checked: no clashes in 2025/26).
            $key = $row['player'] . '|' . ($row['born'] !== '' ? (int) $row['born'] : $row['team']);
            $groups[$key][] = $row;
        }
        fclose($fh);

        return $groups;
    }

    private function createTable(): void
    {
        $fieldColumns = implode(",\n    ", array_map(static fn ($f) => "{$f} REAL", array_keys($this->fields)));

        $this->db->exec('DROP TABLE IF EXISTS ' . self::TABLE);
        $this->db->exec(sprintf(
            'CREATE TABLE %s (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source TEXT NOT NULL,
    season INTEGER NOT NULL,
    player_key TEXT NOT NULL,
    player TEXT NOT NULL,
    born INTEGER,
    nation TEXT,
    pos TEXT,
    is_goalkeeper INTEGER NOT NULL,
    age INTEGER,
    teams TEXT,
    leagues TEXT,
    stints INTEGER NOT NULL,
    sofascore_id INTEGER,
    %s,
    UNIQUE (source, season, player_key)
)',
            self::TABLE,
            $fieldColumns
        ));
    }

    /** Stint with the most minutes: used for identity and as tie-breaker. */
    private function mainStint(array $stints): array
    {
        usort($stints, static fn ($a, $b) => (float) $b['playing_time_min'] <=> (float) $a['playing_time_min']);

        return $stints[0];
    }

    private function sumStints(array $stints, string $column): int|float|null
    {
        $sum = 0;
        foreach ($stints as $s) {
            $value = $this->num($s[$column]);
            if ($value === null) {
                return null; // one unknown stint makes the total unknown
            }
            $sum += $value;
        }

        return $sum;
    }

    private function seasonValue(array $stints, array $main, string $column, string $key, array &$conflicts): int|float|null
    {
        $distinct = array_unique(array_filter(
            array_map(fn ($s) => $this->num($s[$column]), $stints),
            static fn ($v) => $v !== null
        ));
        if (count($distinct) > 1) {
            $conflicts[] = "{$key}: {$column} differs between stints, used the stint with most minutes";
        }

        return $this->num($main[$column]);
    }

    private function savePct(int|float|null $saves, int|float|null $conceded): ?float
    {
        if ($saves === null || $conceded === null || $saves + $conceded == 0) {
            return null;
        }

        return round($saves / ($saves + $conceded) * 100, 1);
    }

    private function num(?string $value): int|float|null
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return $value + 0;
    }

    private function recordImport(string $file, ?string $version, int $rows): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS source_imports (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                source TEXT NOT NULL,
                file TEXT NOT NULL,
                version TEXT,
                rows INTEGER NOT NULL,
                imported_at TEXT NOT NULL
            )'
        );
        $this->db->prepare('INSERT INTO source_imports (source, file, version, rows, imported_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([self::SOURCE, basename($file), $version, $rows, gmdate('c')]);
    }
}
