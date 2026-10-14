<?php

declare(strict_types=1);

namespace App\Inspector;

use App\Entity\StatsScope;
use Doctrine\DBAL\Connection;

/**
 * Read-only queries for the data inspector (a developer tool, not the product UI).
 * Plain SQL on purpose: listing thousands of players does not need entity hydration.
 */
final readonly class InspectorQueries
{
    public function __construct(private Connection $db)
    {
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        return $this->db->fetchAssociative(
            "SELECT (SELECT count(*) FROM player) AS players,
                    (SELECT count(*) FROM team) AS teams,
                    (SELECT count(*) FROM player_season) AS player_seasons,
                    (SELECT count(*) FROM player_competition_stats WHERE scope = 'league') AS league_stats,
                    (SELECT count(*) FROM player_competition_stats WHERE scope = 'CL') AS cl_stats,
                    (SELECT count(*) FROM player_identifier) AS identifiers,
                    (SELECT count(*) FROM import_batch) AS batches"
        ) ?: [];
    }

    /** @return list<array<string, mixed>> */
    public function batches(): array
    {
        return $this->db->fetchAllAssociative('SELECT * FROM import_batch ORDER BY id DESC');
    }

    /**
     * Provider status counts for league stats, for players with at least $minMinutes.
     *
     * @return list<array{origin: string, status: string, players: int}>
     */
    public function originStatuses(int $minMinutes): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT o.key AS origin, o.value->>'status' AS status, count(*) AS players
             FROM player_competition_stats s, jsonb_each(s.origin_checks) o
             WHERE s.scope = 'league' AND s.minutes >= ?
             GROUP BY 1, 2 ORDER BY 1, 2",
            [$minMinutes]
        );
    }

    /** @return list<array<string, mixed>> */
    public function identifierMethods(): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT source, method, count(*) AS identifiers FROM player_identifier GROUP BY 1, 2 ORDER BY 1, 3 DESC'
        );
    }

    /**
     * One row per player season with headline league and CL numbers.
     *
     * @param 'all'|'league'|'cl'|'cl_only'|'flagged' $filter
     *
     * @return list<array<string, mixed>>
     */
    public function players(string $search, string $filter, string $sort, int $limit = 100): array
    {
        $where = match ($filter) {
            'league' => 'league_minutes IS NOT NULL',
            'cl' => 'cl_minutes IS NOT NULL',
            'cl_only' => 'league_minutes IS NULL',
            'flagged' => "league_checks::text ~ '\"status\": \"(partial|suspect|missing)\"'",
            default => 'TRUE',
        };
        $params = [];
        if ($search !== '') {
            $where .= ' AND search_name LIKE :q';
            $params['q'] = '%' . self::unaccent($search) . '%';
        }
        $order = match ($sort) {
            'cl_goals' => 'cl_goals DESC NULLS LAST',
            'league_minutes' => 'league_minutes DESC NULLS LAST',
            'name' => 'name ASC',
            default => 'league_goals DESC NULLS LAST',
        };

        $sql = <<<SQL
            SELECT * FROM (
                SELECT p.id, p.name, p.birth_year, ps.position_group, t.name AS team,
                       l.minutes AS league_minutes,
                       (l.values->>'goals')::numeric AS league_goals,
                       (l.values->>'assists')::numeric AS league_assists,
                       l.origin_checks AS league_checks,
                       c.minutes AS cl_minutes,
                       (c.values->>'goals')::numeric AS cl_goals,
                       (c.values->>'assists')::numeric AS cl_assists,
                       translate(lower(p.name), :accents, :plain) AS search_name
                FROM player_season ps
                JOIN player p ON p.id = ps.player_id
                LEFT JOIN team t ON t.id = ps.main_team_id
                LEFT JOIN player_competition_stats l ON l.player_season_id = ps.id AND l.scope = 'league'
                LEFT JOIN player_competition_stats c ON c.player_season_id = ps.id AND c.scope = 'CL'
            ) players
            WHERE {$where}
            ORDER BY {$order}, name
            LIMIT {$limit}
            SQL;

        $rows = $this->db->fetchAllAssociative($sql, $params + ['accents' => self::ACCENTS, 'plain' => self::PLAIN]);

        return array_map(static fn (array $row) => [
            'league_checks' => $row['league_checks'] !== null ? json_decode($row['league_checks'], true, flags: JSON_THROW_ON_ERROR) : [],
        ] + $row, $rows);
    }

    /** Same character mapping as the SQL translate(), so "mbappe" finds "Mbappé". */
    private const ACCENTS = 'áàâäãåçćčéèêëíìîïıñńóòôöõøřúùûüýÿšşśžđłğ';
    private const PLAIN = 'aaaaaaccceeeeiiiiinnooooooruuuuyyssszdlg';

    private static function unaccent(string $value): string
    {
        return strtr(mb_strtolower($value), array_combine(mb_str_split(self::ACCENTS), mb_str_split(self::PLAIN)));
    }
}
