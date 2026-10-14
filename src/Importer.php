<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Imports one competition season from football-data.org into the database.
 *
 * Each resource (matches, standings, scorers) is fetched and stored independently:
 * if one fails (e.g. 403 for a season outside the plan), the others still import,
 * and the failure is written to import_log.
 */
final class Importer
{
    private const LEAGUE_TABLE_STAGES = ['REGULAR_SEASON', 'LEAGUE_STAGE', 'GROUP_STAGE'];

    /** @var \Closure(string): void */
    private \Closure $log;

    public function __construct(
        private readonly FootballDataClient $client,
        private readonly PDO $db,
        private readonly string $periodFrom,
        private readonly string $periodTo,
        private readonly int $scorersLimit = 100,
        ?\Closure $log = null,
    ) {
        $this->log = $log ?? static function (string $line): void {
        };
    }

    /** @return array{matches: int, skipped: int, standings: int, scorers: int, errors: int} */
    public function import(string $code, int $season): array
    {
        $stats = ['matches' => 0, 'skipped' => 0, 'standings' => 0, 'scorers' => 0, 'errors' => 0];

        $this->step($code, $season, 'matches', $stats, function () use ($code, $season, &$stats): int {
            $data = $this->client->matches($code, ['season' => $season]);
            $this->assertSeason($data['matches'][0]['season'] ?? null, $season);
            [$imported, $skipped] = $this->storeMatches($data);
            $stats['skipped'] = $skipped;

            return $imported;
        });

        $this->step($code, $season, 'standings', $stats, function () use ($code, $season): int {
            $data = $this->client->standings($code, ['season' => $season]);
            $this->assertSeason($data['season'] ?? null, $season);

            return $this->storeStandings($data);
        });

        $this->step($code, $season, 'scorers', $stats, function () use ($code, $season): int {
            $data = $this->client->scorers($code, ['season' => $season, 'limit' => $this->scorersLimit]);
            $this->assertSeason($data['season'] ?? null, $season);

            return $this->storeScorers($data);
        });

        return $stats;
    }

    private function step(string $code, int $season, string $resource, array &$stats, callable $fn): void
    {
        $this->db->beginTransaction();
        try {
            $rows = $fn();
            $this->db->commit();
            $stats[$resource] = $rows;
            $status = $this->client->lastFromCache ? 'cached' : 'ok';
            $this->logImport($code, $season, $resource, $status, 200, null, $rows);
            ($this->log)(sprintf('  %-9s %4d rows (%s)', $resource, $rows, $status));
        } catch (FootballDataException $e) {
            $this->db->rollBack();
            $stats['errors']++;
            $this->logImport($code, $season, $resource, 'error', $e->getCode() ?: null, $e->getMessage(), 0);
            ($this->log)(sprintf('  %-9s ERROR %s: %s', $resource, $e->getCode() ?: '', $e->getMessage()));
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** Refuses data from another season than requested, instead of silently storing it. */
    private function assertSeason(?array $returned, int $requested): void
    {
        if ($returned === null) {
            return;
        }
        $year = (int) substr((string) ($returned['startDate'] ?? ''), 0, 4);
        if ($year !== $requested) {
            throw new FootballDataException(sprintf(
                'Requested season %d but the API returned season %d. This season is probably not available on your plan.',
                $requested,
                $year
            ));
        }
    }

    /** @return array{0: int, 1: int} [imported, skipped outside period] */
    private function storeMatches(array $data): array
    {
        $competition = $data['competition'] ?? null;
        if ($competition === null) {
            throw new FootballDataException('Matches response has no competition.');
        }
        $this->storeCompetition($competition);

        $imported = 0;
        $skipped = 0;
        $now = gmdate('c');

        foreach ($data['matches'] ?? [] as $m) {
            $day = substr($m['utcDate'], 0, 10);
            if ($day < $this->periodFrom || $day > $this->periodTo) {
                $skipped++;
                continue;
            }

            $this->storeSeason($m['season'], (int) $competition['id']);
            $homeId = $this->storeTeam($m['homeTeam'] ?? null);
            $awayId = $this->storeTeam($m['awayTeam'] ?? null);

            $score = Score::normalize($m['score'] ?? []);
            $row = [
                'id' => $m['id'],
                'competition_id' => $competition['id'],
                'season_id' => $m['season']['id'],
                'utc_date' => $m['utcDate'],
                'status' => $m['status'],
                'match_type' => $this->matchType($competition['type'] ?? null, $m['stage'] ?? null),
                'stage' => $m['stage'] ?? null,
                'matchday' => $m['matchday'] ?? null,
                'group_name' => $m['group'] ?? null,
                'home_team_id' => $homeId,
                'away_team_id' => $awayId,
                'home_score' => $score['home'],
                'away_score' => $score['away'],
                'ht_home' => $m['score']['halfTime']['home'] ?? null,
                'ht_away' => $m['score']['halfTime']['away'] ?? null,
                'duration' => $m['score']['duration'] ?? null,
                'pen_home' => $score['penHome'],
                'pen_away' => $score['penAway'],
                'winner' => $m['score']['winner'] ?? null,
                'source_updated' => $m['lastUpdated'] ?? null,
                'imported_at' => $now,
            ];
            $row['missing_fields'] = json_encode($this->missingFields($row));

            Database::upsert($this->db, 'matches', $row);
            $imported++;
        }

        return [$imported, $skipped];
    }

    private function storeStandings(array $data): int
    {
        $competitionId = (int) $data['competition']['id'];
        $this->storeCompetition($data['competition']);
        $seasonId = $this->storeSeason($data['season'], $competitionId);

        // Snapshot: replace whatever we had for this competition season.
        $this->db->prepare('DELETE FROM standings WHERE competition_id = ? AND season_id = ?')
            ->execute([$competitionId, $seasonId]);

        $rows = 0;
        $now = gmdate('c');
        foreach ($data['standings'] ?? [] as $standing) {
            if (($standing['type'] ?? 'TOTAL') !== 'TOTAL'
                || !in_array($standing['stage'] ?? '', self::LEAGUE_TABLE_STAGES, true)
            ) {
                continue;
            }
            foreach ($standing['table'] as $r) {
                Database::upsert($this->db, 'standings', [
                    'competition_id' => $competitionId,
                    'season_id' => $seasonId,
                    'stage' => $standing['stage'],
                    'group_name' => $standing['group'] ?? '',
                    'team_id' => $this->storeTeam($r['team']),
                    'position' => $r['position'],
                    'played' => $r['playedGames'] ?? null,
                    'won' => $r['won'] ?? null,
                    'draw' => $r['draw'] ?? null,
                    'lost' => $r['lost'] ?? null,
                    'goals_for' => $r['goalsFor'] ?? null,
                    'goals_against' => $r['goalsAgainst'] ?? null,
                    'goal_difference' => $r['goalDifference'] ?? null,
                    'points' => $r['points'] ?? null,
                    'imported_at' => $now,
                ], ['competition_id', 'season_id', 'stage', 'group_name', 'team_id']);
                $rows++;
            }
        }

        return $rows;
    }

    private function storeScorers(array $data): int
    {
        $competitionId = (int) $data['competition']['id'];
        $this->storeCompetition($data['competition']);
        $seasonId = $this->storeSeason($data['season'], $competitionId);

        $this->db->prepare('DELETE FROM player_competition_stats WHERE competition_id = ? AND season_id = ?')
            ->execute([$competitionId, $seasonId]);

        $rows = 0;
        $now = gmdate('c');
        foreach ($data['scorers'] ?? [] as $s) {
            $p = $s['player'];
            Database::upsert($this->db, 'players', [
                'id' => $p['id'],
                'name' => $p['name'],
                'first_name' => $p['firstName'] ?? null,
                'last_name' => $p['lastName'] ?? null,
                'date_of_birth' => $p['dateOfBirth'] ?? null,
                'nationality' => $p['nationality'] ?? null,
                'position' => $p['position'] ?? $p['section'] ?? null,
            ]);

            Database::upsert($this->db, 'player_competition_stats', [
                'player_id' => $p['id'],
                'competition_id' => $competitionId,
                'season_id' => $seasonId,
                'team_id' => $this->storeTeam($s['team'] ?? null),
                'played_matches' => $s['playedMatches'] ?? null,
                'goals' => $s['goals'] ?? null,
                'assists' => $s['assists'] ?? null,
                'penalties' => $s['penalties'] ?? null,
                'imported_at' => $now,
            ], ['player_id', 'competition_id', 'season_id']);
            $rows++;
        }

        return $rows;
    }

    private function storeCompetition(array $c): void
    {
        Database::upsert($this->db, 'competitions', [
            'id' => $c['id'],
            'code' => $c['code'],
            'name' => $c['name'],
            'type' => $c['type'] ?? null,
            'emblem' => $c['emblem'] ?? null,
        ]);
    }

    private function storeSeason(array $s, int $competitionId): int
    {
        Database::upsert($this->db, 'seasons', [
            'id' => $s['id'],
            'competition_id' => $competitionId,
            'start_date' => $s['startDate'],
            'end_date' => $s['endDate'] ?? null,
            'winner_team_id' => $s['winner']['id'] ?? null,
        ]);

        return (int) $s['id'];
    }

    /** Returns the team id, or null for an undecided (TBD) team. */
    private function storeTeam(?array $t): ?int
    {
        if (empty($t['id'])) {
            return null;
        }

        Database::upsert($this->db, 'teams', [
            'id' => $t['id'],
            'name' => $t['name'] ?? $t['shortName'] ?? 'Unknown',
            'short_name' => $t['shortName'] ?? null,
            'tla' => $t['tla'] ?? null,
            'crest' => $t['crest'] ?? null,
        ]);

        return (int) $t['id'];
    }

    private function matchType(?string $competitionType, ?string $stage): string
    {
        return match (true) {
            $competitionType === 'LEAGUE' => 'league',
            $stage === 'LEAGUE_STAGE' => 'league_phase',
            $stage === 'GROUP_STAGE' => 'group',
            $stage !== null && str_contains($stage, 'QUALIFICATION'),
            $stage === 'PRELIMINARY_ROUND' => 'qualification',
            default => 'knockout',
        };
    }

    /**
     * Fields that should be present for this match but are not.
     * Only finished matches are expected to have scores.
     *
     * @return list<string>
     */
    private function missingFields(array $row): array
    {
        $missing = [];

        foreach (['home_team_id', 'away_team_id'] as $field) {
            if ($row[$field] === null) {
                $missing[] = $field;
            }
        }

        if (in_array($row['match_type'], ['league', 'league_phase', 'group'], true) && $row['matchday'] === null) {
            $missing[] = 'matchday';
        }

        if ($row['status'] === 'FINISHED') {
            foreach (['home_score', 'away_score', 'ht_home', 'ht_away', 'winner', 'duration'] as $field) {
                if ($row[$field] === null) {
                    $missing[] = $field;
                }
            }
            if ($row['duration'] === 'PENALTY_SHOOTOUT' && $row['pen_home'] === null) {
                $missing[] = 'pen_home';
                $missing[] = 'pen_away';
            }
        }

        return $missing;
    }

    private function logImport(string $code, int $season, string $resource, string $status, ?int $http, ?string $message, int $rows): void
    {
        $this->db->prepare(
            'INSERT INTO import_log (competition_code, season, resource, status, http_code, message, rows, fetched_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$code, $season, $resource, $status, $http, $message, $rows, gmdate('c')]);
    }
}
