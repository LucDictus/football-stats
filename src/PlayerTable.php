<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * The all-in-one player table: Top-5 dataset stats (ext_player_seasons) plus the
 * linked football-data.org Champions League / World Cup top-scorer numbers.
 *
 * Columns come from config/fields.php, so a field added there shows up here.
 * NULL is kept as NULL all the way to the template, and sorts last.
 */
final class PlayerTable
{
    public const PER_PAGE = 100;

    public const LEAGUES = ['PL' => 'Premier League', 'PD' => 'La Liga', 'SA' => 'Serie A', 'BL1' => 'Bundesliga', 'FL1' => 'Ligue 1'];
    public const POSITIONS = ['all' => 'All positions', 'GK' => 'Goalkeepers', 'DF' => 'Defenders', 'MF' => 'Midfielders', 'FW' => 'Forwards'];

    /** Extra columns from football-data.org top-scorer lists (via player_links). */
    private const EXTRA_GROUP = 'Champions League & World Cup';
    private const EXTRA_COLUMNS = [
        'cl_apps' => ['short' => 'CL Apps', 'label' => 'Champions League appearances (top-scorer list only)', 'code' => 'CL', 'stat' => 'played_matches'],
        'cl_goals' => ['short' => 'CL G', 'label' => 'Champions League goals (top-scorer list only)', 'code' => 'CL', 'stat' => 'goals'],
        'cl_assists' => ['short' => 'CL A', 'label' => 'Champions League assists (top-scorer list only)', 'code' => 'CL', 'stat' => 'assists'],
        'wc_goals' => ['short' => 'WC G', 'label' => 'World Cup 2026 goals (top-scorer list only)', 'code' => 'WC', 'stat' => 'goals'],
        'wc_assists' => ['short' => 'WC A', 'label' => 'World Cup 2026 assists (top-scorer list only)', 'code' => 'WC', 'stat' => 'assists'],
    ];

    /** @var array<string, list<array{key: string, short: string, label: string, format: string, per90: bool, source: string}>> */
    private array $groups = [];

    public function __construct(private readonly PDO $db, array $registry)
    {
        $origins = $registry['sources'][Top5Importer::SOURCE]['origins'] ?? [];

        foreach ($registry['fields'] as $key => $field) {
            $source = null;
            foreach ($field['sources'] as $s) {
                if (($s['source'] ?? null) === Top5Importer::SOURCE) {
                    $source = $s;
                }
            }
            if ($source === null) {
                continue; // no data for this field in the table (see the coverage page)
            }

            $origin = 'derived';
            foreach ($origins as $prefix => $name) {
                if ($source['column'] !== null && str_starts_with($source['column'], $prefix)) {
                    $origin = $name;
                }
            }
            if ($source['column'] !== null && $origin === 'derived') {
                $origin = 'FBref'; // playing_time_* columns come from FBref
            }

            $this->groups[$field['category']][] = [
                'key' => $key,
                'short' => $field['short'],
                'label' => $field['label'],
                'format' => $field['format'],
                'per90' => $field['format'] !== 'pct' && $field['category'] !== 'Playing time',
                'source' => $origin . ($source['column'] ? " ({$source['column']})" : ''),
            ];
        }

        foreach (self::EXTRA_COLUMNS as $key => $col) {
            $this->groups[self::EXTRA_GROUP][] = [
                'key' => $key,
                'short' => $col['short'],
                'label' => $col['label'],
                'format' => 'int',
                'per90' => false,
                'source' => 'football-data.org /scorers',
            ];
        }
    }

    public function isImported(): bool
    {
        return (bool) $this->db->query(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = '" . Top5Importer::TABLE . "'"
        )->fetchColumn();
    }

    /** @return array<string, list<array>> category => columns */
    public function groups(): array
    {
        return $this->groups;
    }

    /** @return list<string> */
    public function sortableKeys(): array
    {
        $keys = ['player', 'age'];
        foreach ($this->groups as $columns) {
            foreach ($columns as $c) {
                $keys[] = $c['key'];
            }
        }

        return $keys;
    }

    /**
     * @param array{league: string, position: string, min_minutes: int, q: string, sort: string, dir: string, per90: bool, page: int} $f
     * @return array{rows: list<array>, total: int}
     */
    public function fetch(array $f): array
    {
        $where = ['1 = 1'];
        $params = [];

        if (isset(self::LEAGUES[$f['league']])) {
            $where[] = "(',' || e.leagues || ',') LIKE :league";
            $params['league'] = '%,' . $f['league'] . ',%';
        }
        if ($f['position'] !== 'all' && isset(self::POSITIONS[$f['position']])) {
            $where[] = 'e.pos LIKE :pos';
            $params['pos'] = '%' . $f['position'] . '%';
        }
        if ($f['min_minutes'] > 0) {
            $where[] = 'e.minutes >= :min';
            $params['min'] = $f['min_minutes'];
        }
        if ($f['q'] !== '') {
            $where[] = 'e.player LIKE :q';
            $params['q'] = '%' . $f['q'] . '%';
        }

        $extraSelect = [];
        foreach (self::EXTRA_COLUMNS as $key => $col) {
            $extraSelect[] = sprintf(
                "SUM(CASE WHEN c.code = '%s' THEN s.%s END) AS %s",
                $col['code'],
                $col['stat'],
                $key
            );
        }
        $hasLinks = (bool) $this->db->query("SELECT 1 FROM sqlite_master WHERE name = 'player_links'")->fetchColumn();
        $extraJoin = $hasLinks
            ? 'LEFT JOIN (SELECT pl.ext_id, ' . implode(', ', $extraSelect) . '
                          FROM player_links pl
                          JOIN player_competition_stats s ON s.player_id = pl.fd_player_id
                          JOIN competitions c ON c.id = s.competition_id
                          GROUP BY pl.ext_id) x ON x.ext_id = e.id'
            : 'LEFT JOIN (SELECT NULL AS ext_id, ' . implode(', ', array_map(
                static fn ($k) => "NULL AS {$k}",
                array_keys(self::EXTRA_COLUMNS)
            )) . ') x ON 0';

        $sortKey = in_array($f['sort'], $this->sortableKeys(), true) ? $f['sort'] : 'goals';
        $sortCol = in_array($sortKey, ['player', 'age'], true) ? "e.{$sortKey}"
            : (isset(self::EXTRA_COLUMNS[$sortKey]) ? "x.{$sortKey}" : "e.{$sortKey}");
        if ($f['per90'] && ($this->column($sortKey)['per90'] ?? false) === true) {
            $sortCol = "({$sortCol} * 90.0 / NULLIF(e.minutes, 0))";
        }
        $dir = $f['dir'] === 'asc' ? 'ASC' : 'DESC';

        $from = 'FROM ' . Top5Importer::TABLE . " e {$extraJoin} WHERE " . implode(' AND ', $where);

        $count = $this->db->prepare("SELECT COUNT(*) {$from}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $stmt = $this->db->prepare(sprintf(
            'SELECT e.*, %s %s ORDER BY (%s IS NULL), %s %s, e.minutes DESC LIMIT %d OFFSET %d',
            implode(', ', array_map(static fn ($k) => "x.{$k}", array_keys(self::EXTRA_COLUMNS))),
            $from,
            $sortCol,
            $sortCol,
            $dir,
            self::PER_PAGE,
            max(0, $f['page'] - 1) * self::PER_PAGE
        ));
        $stmt->execute($params);

        return ['rows' => $stmt->fetchAll(), 'total' => $total];
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ' . Top5Importer::TABLE . ' WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    /** football-data.org player ids linked to this dataset player. */
    public function linkedFootballDataIds(int $id): array
    {
        if (!$this->db->query("SELECT 1 FROM sqlite_master WHERE name = 'player_links'")->fetchColumn()) {
            return [];
        }
        $stmt = $this->db->prepare('SELECT fd_player_id, method FROM player_links WHERE ext_id = ?');
        $stmt->execute([$id]);

        return $stmt->fetchAll();
    }

    /** @return array{key: string, short: string, label: string, format: string, per90: bool, source: string}|null */
    public function column(string $key): ?array
    {
        foreach ($this->groups as $columns) {
            foreach ($columns as $c) {
                if ($c['key'] === $key) {
                    return $c;
                }
            }
        }

        return null;
    }

    /** Formatted value, or null when the source has no value. Never turns null into 0. */
    public static function format(mixed $value, array $column, bool $per90, mixed $minutes): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($per90 && $column['per90']) {
            return $minutes > 0 ? number_format($value * 90 / $minutes, 2) : null;
        }

        return match ($column['format']) {
            'dec' => number_format((float) $value, 1),
            'pct' => number_format((float) $value, 1),
            default => (string) (int) round((float) $value),
        };
    }
}
