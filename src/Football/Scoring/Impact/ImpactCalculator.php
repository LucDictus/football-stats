<?php

declare(strict_types=1);

namespace App\Football\Scoring\Impact;

use App\Entity\PlayerCompetitionStats;
use App\Football\Data\Origin;
use App\Football\Data\OriginStatus;
use App\Football\Scoring\ScoringCandidate;

/**
 * Calculates each player's contribution in goals (see ImpactModel). Pure and deterministic.
 *
 * Team totals are built from every candidate of the team, also those who are not ranked:
 * a team's xG is everybody's. Season totals from a provider that covers only part of the
 * season (a transfer between leagues) are extrapolated from its rate to the full minutes.
 */
final class ImpactCalculator
{
    public const COMPONENTS = ['shooting', 'finishing', 'creation', 'build_up', 'goalkeeping', 'defence'];

    /** How strong the evidence for each component is (shown with the breakdown). */
    public const EVIDENCE = [
        'shooting' => 'strong',
        'finishing' => 'moderate',
        'creation' => 'strong',
        'build_up' => 'moderate',
        'goalkeeping' => 'moderate',
        'defence' => 'weak',
    ];

    /**
     * @param list<ScoringCandidate> $candidates all players of the scope (team totals)
     * @param list<ScoringCandidate> $scored     the players to calculate contribution for
     *
     * @return array<int, array{goals: array<string, ?float>, total: ?float, replacement: float, above: ?float}> by player id
     */
    public function calculate(ImpactModel $model, array $candidates, array $scored): array
    {
        $teams = $this->teams($model, $candidates);
        $leagueAverage = $this->leagueAverages($model, $teams);

        $raw = [];
        foreach ($scored as $candidate) {
            $raw[$candidate->playerId] = $this->player($model, $candidate, $teams[self::teamKey($candidate)] ?? null, $leagueAverage);
        }

        // Replacement level per role: a low percentile of contribution per 90.
        $perNinety = [];
        foreach ($scored as $candidate) {
            $total = $raw[$candidate->playerId]['total'];
            if ($total !== null && $candidate->stats->getMinutes() > 0) {
                $perNinety[(string) $candidate->positionGroup][] = $total / $candidate->stats->getMinutes() * 90;
            }
        }
        $replacement = [];
        foreach ($perNinety as $group => $values) {
            $replacement[$group] = self::percentile($values, $model->replacementPercentile);
        }

        $results = [];
        foreach ($scored as $candidate) {
            $r = $raw[$candidate->playerId];
            $level = ($replacement[(string) $candidate->positionGroup] ?? 0.0) * $candidate->stats->getMinutes() / 90;
            $results[$candidate->playerId] = $r + [
                'replacement' => $level,
                'above' => $r['total'] === null ? null : $r['total'] - $level,
            ];
        }

        return $results;
    }

    /**
     * @param array{xg: float, xa: float, xg_buildup: float, defensive_actions: float, psxg_faced: float, gk_minutes: float, league: ?string}|null $team
     * @param array<string, float>                                                                                                                 $leagueAverage league => expected goals on target conceded per 90
     *
     * @return array{goals: array<string, ?float>, total: ?float}
     */
    private function player(ImpactModel $model, ScoringCandidate $c, ?array $team, array $leagueAverage): array
    {
        $s = $c->stats;
        $goals = array_fill_keys(self::COMPONENTS, null);

        $npxg = self::seasonTotal($s, 'np_xg');
        $xa = self::seasonTotal($s, 'xa');
        $buildUp = self::seasonTotal($s, 'xg_buildup');
        if ($npxg !== null && $xa !== null) {
            $goals['shooting'] = $model->shooterShare * $npxg;
            $goals['creation'] = $model->passerShare * $xa;
        }
        if ($buildUp !== null && $team !== null && $team['xg_buildup'] > 0) {
            // Pool: the build-up share of every chance, plus the passer's share of unassisted ones.
            $pool = $model->buildUpShare * $team['xg'] + $model->passerShare * max(0.0, $team['xg'] - $team['xa']);
            $goals['build_up'] = $pool * $buildUp / $team['xg_buildup'];
        }
        $npGoals = self::seasonTotal($s, 'np_goals');
        $shots = self::seasonTotal($s, 'shots');
        if ($npGoals !== null && $npxg !== null && $shots !== null) {
            $goals['finishing'] = $model->shooterShare * ($npGoals - $npxg) * $shots / ($shots + $model->finishingPriorShots);
        }

        if ($c->positionGroup === 'GK') {
            $prevented = self::seasonTotal($s, 'goals_prevented');
            $faced = ($s->value('saves') ?? 0.0) + ($s->value('goals_conceded') ?? 0.0);
            if ($prevented !== null) {
                $goals['goalkeeping'] = $prevented * $faced / ($faced + $model->goalkeepingPriorShotsOnTarget);
            }
        } elseif ($team !== null && $team['defensive_actions'] > 0 && $team['gk_minutes'] >= $model->minTeamGoalkeeperMinutes
            && isset($leagueAverage[(string) $team['league']])) {
            $mine = self::defensiveActions($model, $s);
            if ($mine !== null) {
                $teamPrevented = ($leagueAverage[(string) $team['league']] - $team['psxg_faced'] / $team['gk_minutes'] * 90) * $team['gk_minutes'] / 90;
                $goals['defence'] = $teamPrevented * $mine / $team['defensive_actions'];
            }
        }

        // Shooting and creation come together (Understat); without them the attack is unknown.
        $known = array_filter($goals, static fn (?float $g) => $g !== null);

        return ['goals' => $goals, 'total' => $goals['shooting'] === null ? null : array_sum($known)];
    }

    /**
     * Team totals from every player of the team (only providers trusted or extrapolated).
     *
     * @param list<ScoringCandidate> $candidates
     *
     * @return array<string, array{xg: float, xa: float, xg_buildup: float, defensive_actions: float, psxg_faced: float, gk_minutes: float, league: ?string}>
     */
    private function teams(ImpactModel $model, array $candidates): array
    {
        $teams = [];
        foreach ($candidates as $c) {
            $key = self::teamKey($c);
            if ($key === null) {
                continue;
            }
            $teams[$key] ??= ['xg' => 0.0, 'xa' => 0.0, 'xg_buildup' => 0.0, 'defensive_actions' => 0.0, 'psxg_faced' => 0.0, 'gk_minutes' => 0.0, 'league' => $c->league];
            $teams[$key]['xg'] += self::seasonTotal($c->stats, 'np_xg') ?? 0.0;
            $teams[$key]['xa'] += self::seasonTotal($c->stats, 'xa') ?? 0.0;
            $teams[$key]['xg_buildup'] += self::seasonTotal($c->stats, 'xg_buildup') ?? 0.0;
            if ($c->positionGroup === 'GK') {
                $conceded = $c->stats->value('goals_conceded');
                $prevented = $c->stats->value('goals_prevented');
                if ($conceded !== null && $prevented !== null && $c->stats->originStatus(Origin::Sofascore) === OriginStatus::Ok) {
                    $teams[$key]['psxg_faced'] += $conceded + $prevented;
                    $teams[$key]['gk_minutes'] += (float) $c->stats->originMinutes(Origin::Sofascore);
                }
            } else {
                $teams[$key]['defensive_actions'] += self::defensiveActions($model, $c->stats) ?? 0.0;
            }
        }

        return $teams;
    }

    /**
     * @param array<string, array{psxg_faced: float, gk_minutes: float, league: ?string}> $teams
     *
     * @return array<string, float> league => average expected goals on target conceded per 90
     */
    private function leagueAverages(ImpactModel $model, array $teams): array
    {
        $perLeague = [];
        foreach ($teams as $team) {
            if ($team['league'] !== null && $team['gk_minutes'] >= $model->minTeamGoalkeeperMinutes) {
                $perLeague[$team['league']][] = $team['psxg_faced'] / $team['gk_minutes'] * 90;
            }
        }

        return array_map(static fn (array $values) => array_sum($values) / count($values), $perLeague);
    }

    private static function defensiveActions(ImpactModel $model, PlayerCompetitionStats $s): ?float
    {
        $sum = 0.0;
        foreach ($model->defensiveActions as $field) {
            $value = self::seasonTotal($s, $field);
            if ($value === null) {
                return null;
            }
            $sum += $value;
        }

        return $sum;
    }

    /**
     * A field as a season total: as is when its provider covers the season, extrapolated from
     * the provider's own minutes when it covers part of it, unknown otherwise.
     */
    private static function seasonTotal(PlayerCompetitionStats $s, string $field): ?float
    {
        $value = $s->value($field);
        if ($value === null) {
            return null;
        }
        $status = $s->fieldStatus($field);
        if ($status === OriginStatus::Ok) {
            return $value;
        }
        if ($status !== OriginStatus::Partial) {
            return null;
        }
        $origin = $s->origin($field);
        $minutes = $origin !== null && $origin !== Origin::Derived ? $s->originMinutes($origin) : null;

        return $minutes !== null && $minutes >= PlayerCompetitionStats::MIN_PER90_MINUTES ? $value / $minutes * $s->getMinutes() : null;
    }

    /**
     * Linear-interpolation percentile (p between 0 and 1).
     *
     * @param non-empty-list<float> $values
     */
    private static function percentile(array $values, float $p): float
    {
        sort($values);
        $position = $p * (count($values) - 1);
        $lower = (int) floor($position);
        $upper = min($lower + 1, count($values) - 1);

        return $values[$lower] + ($position - $lower) * ($values[$upper] - $values[$lower]);
    }

    private static function teamKey(ScoringCandidate $c): ?string
    {
        return $c->team;
    }
}
