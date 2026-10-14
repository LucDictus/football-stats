<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Player data from the imported database (player_competition_stats).
 *
 * Note: the free API only provides the top scorers per competition, so a player
 * only has a row for a competition where they made that list.
 */
final class PlayerRepository
{
    public const SORTS = [
        'goals' => 'Goals',
        'assists' => 'Assists',
        'contributions' => 'Goals + assists',
        'apps' => 'Matches',
    ];

    private const ORDER_BY = [
        'goals' => 'goals DESC, assists DESC, apps ASC',
        'assists' => 'assists DESC, goals DESC, apps ASC',
        'contributions' => 'contributions DESC, goals DESC, apps ASC',
        'apps' => 'apps DESC, goals DESC',
    ];

    public function __construct(private readonly PDO $db)
    {
    }

    /** Competitions that have player data, e.g. [['code' => 'PL', 'name' => ..., 'type' => 'LEAGUE'], ...] */
    public function competitions(): array
    {
        return $this->db->query(
            'SELECT DISTINCT c.code, c.name, c.type
             FROM player_competition_stats s
             JOIN competitions c ON c.id = s.competition_id
             ORDER BY c.type DESC, c.name'
        )->fetchAll();
    }

    /**
     * Ranking with totals over the selected competitions.
     *
     * @param string $filter 'all' | 'leagues' | 'cups' | a competition code
     */
    public function ranking(string $filter = 'all', string $sort = 'goals', string $search = '', int $limit = 200): array
    {
        $where = [];
        $params = [];

        if ($filter === 'leagues') {
            $where[] = "c.type = 'LEAGUE'";
        } elseif ($filter === 'cups') {
            $where[] = "c.type = 'CUP'";
        } elseif ($filter !== 'all') {
            $where[] = 'c.code = :code';
            $params['code'] = $filter;
        }

        if ($search !== '') {
            $where[] = 'p.name LIKE :search';
            $params['search'] = '%' . $search . '%';
        }

        $sql = sprintf(
            "SELECT p.id, p.name, p.nationality, p.position,
                    GROUP_CONCAT(DISTINCT t.name)                       AS teams,
                    SUM(COALESCE(s.played_matches, 0))                  AS apps,
                    SUM(COALESCE(s.goals, 0))                           AS goals,
                    SUM(COALESCE(s.assists, 0))                         AS assists,
                    SUM(COALESCE(s.goals, 0) + COALESCE(s.assists, 0))  AS contributions,
                    SUM(COALESCE(s.penalties, 0))                       AS penalties,
                    GROUP_CONCAT(c.code || ':' || COALESCE(s.goals, 0)) AS breakdown
             FROM player_competition_stats s
             JOIN players p      ON p.id = s.player_id
             JOIN competitions c ON c.id = s.competition_id
             LEFT JOIN teams t   ON t.id = s.team_id
             %s
             GROUP BY p.id
             ORDER BY %s
             LIMIT %d",
            $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '',
            self::ORDER_BY[$sort] ?? self::ORDER_BY['goals'],
            $limit
        );

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return array_map(static function (array $row): array {
            // "PL:22,CL:8" -> ['PL' => 22, 'CL' => 8]
            $goalsPerCompetition = [];
            foreach (array_filter(explode(',', (string) $row['breakdown'])) as $part) {
                [$code, $goals] = explode(':', $part, 2);
                $goalsPerCompetition[$code] = (int) $goals;
            }
            $row['breakdown'] = $goalsPerCompetition;

            return $row;
        }, $stmt->fetchAll());
    }

    public function player(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM players WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    /** One row per competition the player has stats for. */
    public function breakdown(int $playerId): array
    {
        $stmt = $this->db->prepare(
            "SELECT c.code, c.name AS competition, c.type, se.start_date, se.end_date,
                    t.name AS team, t.crest,
                    s.played_matches, s.goals, s.assists, s.penalties, s.source, s.imported_at
             FROM player_competition_stats s
             JOIN competitions c ON c.id = s.competition_id
             JOIN seasons se     ON se.id = s.season_id
             LEFT JOIN teams t   ON t.id = s.team_id
             WHERE s.player_id = ?
             ORDER BY c.type DESC, s.goals DESC"
        );
        $stmt->execute([$playerId]);

        return $stmt->fetchAll();
    }

    /**
     * Competitions where one of the player's teams played (per imported matches),
     * but the player is not in that competition's top-scorer list.
     *
     * @return list<string> competition codes
     */
    public function competitionsWithoutStats(int $playerId): array
    {
        $stmt = $this->db->prepare(
            'SELECT DISTINCT c.code
             FROM matches m
             JOIN competitions c ON c.id = m.competition_id
             WHERE (m.home_team_id IN (SELECT team_id FROM player_competition_stats WHERE player_id = :id)
                 OR m.away_team_id IN (SELECT team_id FROM player_competition_stats WHERE player_id = :id))
               AND m.competition_id NOT IN (SELECT competition_id FROM player_competition_stats WHERE player_id = :id)'
        );
        $stmt->execute(['id' => $playerId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
