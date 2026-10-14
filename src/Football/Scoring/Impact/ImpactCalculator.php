<?php

declare(strict_types=1);

namespace App\Football\Scoring\Impact;

use App\Entity\PlayerCompetitionStats;
use App\Football\Assessment\CompetitionCatalog;
use App\Football\Data\Origin;
use App\Football\Data\OriginStatus;
use App\Football\Scoring\ScoringCandidate;

/**
 * Calculates each player's contribution in goals (see ImpactModel). Pure and deterministic.
 *
 * Team totals are built from every candidate of the team, also those who are not ranked:
 * a team's xG is everybody's. Season totals from a provider that covers only part of the
 * season (a transfer between leagues) are extrapolated from its rate to the full minutes.
 *
 * The SofaScore fallback is calibrated on the players both providers cover: its xG and xA
 * are scaled to Understat's level, and build-up (xGBuildup is Understat-only) is estimated
 * from accurate passes with the role's xGBuildup per accurate pass.
 */
/**
 * @phpstan-type Calibration array{xg: float, xa: float, build_up_per_pass: array<string, float>}
 */
final class ImpactCalculator
{
    public const COMPONENTS = ['shooting', 'finishing', 'creation', 'build_up', 'goalkeeping', 'defence', 'errors'];

    /** How strong the evidence for each component is (shown with the breakdown). */
    public const EVIDENCE = [
        'shooting' => 'strong',
        'finishing' => 'moderate',
        'creation' => 'strong',
        'build_up' => 'moderate',
        'goalkeeping' => 'moderate',
        'defence' => 'weak',
        'errors' => 'moderate',
    ];

    /**
     * @param list<ScoringCandidate> $candidates all players of the scope (team totals)
     * @param list<ScoringCandidate> $scored     the players to calculate contribution for
     * @param list<int>|null         $reference  player ids that define the replacement level (default: all scored)
     *
     * @return array<int, array{goals: array<string, ?float>, total: ?float, replacement: float, above: ?float, attack_source: ?string, calibration?: array{xg: float, xa: float, build_up_per_pass: ?float}}> by player id
     */
    public function calculate(ImpactModel $model, array $candidates, array $scored, ?array $reference = null): array
    {
        $calibration = $this->calibration($model, $candidates);
        $teams = $this->teams($model, $candidates, $calibration);
        $leagueAverage = $this->leagueAverages($model, $teams);
        $errorRate = $model->errorGoals !== null ? self::errorRates($scored) : [];

        $raw = [];
        foreach ($scored as $candidate) {
            $raw[$candidate->playerId] = $this->player($model, $candidate, $teams[self::teamKey($candidate)] ?? null, $leagueAverage, $calibration, $errorRate);
        }

        // Replacement level per role: a low percentile of contribution per 90 among the reference players.
        $referenceIds = $reference === null ? null : array_flip($reference);
        $perNinety = [];
        foreach ($scored as $candidate) {
            if ($referenceIds !== null && !isset($referenceIds[$candidate->playerId])) {
                continue;
            }
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
     * @param Calibration                                                                                                                          $calibration
     * @param array<string, float>                                                                                                                 $errorRate     role => errors leading to a goal per 90
     *
     * @return array{goals: array<string, ?float>, total: ?float, attack_source: ?string, calibration?: array{xg: float, xa: float, build_up_per_pass: ?float}}
     */
    private function player(ImpactModel $model, ScoringCandidate $c, ?array $team, array $leagueAverage, array $calibration, array $errorRate): array
    {
        $s = $c->stats;
        $goals = array_fill_keys(self::COMPONENTS, null);
        if ($model->errorGoals === null) {
            unset($goals['errors']); // models without it keep exactly their stored breakdown
        }

        [$npxg, $xa, $buildUp, $source] = self::attack($model, $c, $calibration);
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
            $faced = (self::seasonTotal($s, 'saves') ?? 0.0) + (self::seasonTotal($s, 'goals_conceded') ?? 0.0);
            if ($prevented !== null) {
                $goals['goalkeeping'] = $prevented * $faced / ($faced + $model->goalkeepingPriorShotsOnTarget);
            }
        } elseif ($team !== null && $team['defensive_actions'] > 0 && $team['gk_minutes'] >= $model->minTeamGoalkeeperMinutes
            && isset($leagueAverage[(string) $team['league']], CompetitionCatalog::LEAGUE_MATCHES[(string) $team['league']])) {
            $mine = self::defensiveActions($model, $s);
            if ($mine !== null) {
                // Rate over the minutes the team's goalkeeping data covers, applied to the whole season.
                $rate = $team['psxg_faced'] / $team['gk_minutes'] * 90;
                $teamPrevented = ($leagueAverage[(string) $team['league']] - $rate) * CompetitionCatalog::LEAGUE_MATCHES[(string) $team['league']];
                $goals['defence'] = $teamPrevented * $mine / $team['defensive_actions'];
            }
        }
        if ($model->errorGoals !== null && $c->positionGroup !== 'GK') {
            // A keeper's errors are already in his goals prevented.
            $goals['errors'] = -$model->errorGoals * self::errors($model, $s, $errorRate[(string) $c->positionGroup] ?? 0.0);
        }

        // Without npxG and xA the attack is unknown, so the contribution is unknown (never zero).
        $known = array_filter($goals, static fn (?float $g) => $g !== null);

        $result = ['goals' => $goals, 'total' => $goals['shooting'] === null ? null : array_sum($known), 'attack_source' => $source];
        if ($source === Origin::Sofascore->value) {
            $result['calibration'] = ['xg' => $calibration['xg'], 'xa' => $calibration['xa'], 'build_up_per_pass' => $calibration['build_up_per_pass'][(string) $c->positionGroup] ?? null];
        }

        return $result;
    }

    /**
     * Non-penalty xG, xA and xGBuildup: Understat when it has the player, otherwise SofaScore
     * calibrated to Understat's level (see calibration()).
     *
     * @param Calibration $calibration
     *
     * @return array{0: ?float, 1: ?float, 2: ?float, 3: ?string} npxG, xA, xGBuildup, source
     */
    private static function attack(ImpactModel $model, ScoringCandidate $c, array $calibration): array
    {
        $s = $c->stats;
        $npxg = self::seasonTotal($s, 'np_xg');
        $xa = self::seasonTotal($s, 'xa');
        if ($npxg !== null && $xa !== null) {
            return [$npxg, $xa, self::seasonTotal($s, 'xg_buildup'), Origin::Understat->value];
        }
        [$npxg, $xa] = self::sofascoreAttack($model, $s);
        if ($npxg === null || $xa === null) {
            return [null, null, null, null];
        }
        $perPass = $calibration['build_up_per_pass'][(string) $c->positionGroup] ?? null;
        $passes = self::seasonTotal($s, 'accurate_passes');

        return [$npxg * $calibration['xg'], $xa * $calibration['xa'], $perPass !== null && $passes !== null ? $passes * $perPass : null, Origin::Sofascore->value];
    }

    /**
     * SofaScore's npxG (its xG includes penalties) and xA, uncalibrated. SofaScore leaves them
     * empty when there was nothing to measure.
     *
     * @return array{0: ?float, 1: ?float}
     */
    private static function sofascoreAttack(ImpactModel $model, PlayerCompetitionStats $s): array
    {
        if (!$s->originStatus(Origin::Sofascore)->usable()) {
            return [null, null];
        }
        $xg = self::seasonTotal($s, 'xg_sofascore') ?? (self::seasonTotal($s, 'shots') === 0.0 ? 0.0 : null);
        $xa = self::seasonTotal($s, 'xa_sofascore') ?? (self::seasonTotal($s, 'key_passes') === 0.0 ? 0.0 : null);
        if ($xg === null || $xa === null) {
            return [null, null];
        }

        return [max(0.0, $xg - $model->penaltyXg * (self::seasonTotal($s, 'penalties_taken') ?? 0.0)), $xa];
    }

    /**
     * Calibrates the SofaScore fallback on the players with full-season data from both
     * providers: ratio of the sums of Understat's to SofaScore's npxG and xA, and per role
     * the sum of xGBuildup over the sum of accurate passes. Without enough such players the
     * scale stays 1 and build-up is not estimated.
     *
     * @param list<ScoringCandidate> $candidates
     *
     * @return Calibration
     */
    private function calibration(ImpactModel $model, array $candidates): array
    {
        $sums = ['us_xg' => 0.0, 'ss_xg' => 0.0, 'us_xa' => 0.0, 'ss_xa' => 0.0];
        $count = 0;
        $buildUp = [];
        foreach ($candidates as $c) {
            $s = $c->stats;
            if ($s->originStatus(Origin::Understat) !== OriginStatus::Ok || $s->originStatus(Origin::Sofascore) !== OriginStatus::Ok) {
                continue;
            }
            $npxg = $s->value('np_xg');
            $xa = $s->value('xa');
            [$ssXg, $ssXa] = self::sofascoreAttack($model, $s);
            if ($npxg !== null && $xa !== null && $ssXg !== null && $ssXa !== null) {
                $sums['us_xg'] += $npxg;
                $sums['ss_xg'] += $ssXg;
                $sums['us_xa'] += $xa;
                $sums['ss_xa'] += $ssXa;
                ++$count;
            }
            $xgBuildup = $s->value('xg_buildup');
            $passes = $s->value('accurate_passes');
            if ($xgBuildup !== null && $passes !== null) {
                $group = (string) $c->positionGroup;
                $buildUp[$group] ??= ['xg_buildup' => 0.0, 'passes' => 0.0, 'count' => 0];
                $buildUp[$group]['xg_buildup'] += $xgBuildup;
                $buildUp[$group]['passes'] += $passes;
                ++$buildUp[$group]['count'];
            }
        }

        $enough = $count >= $model->minCalibrationPlayers;

        return [
            'xg' => $enough && $sums['ss_xg'] > 0 ? $sums['us_xg'] / $sums['ss_xg'] : 1.0,
            'xa' => $enough && $sums['ss_xa'] > 0 ? $sums['us_xa'] / $sums['ss_xa'] : 1.0,
            'build_up_per_pass' => array_map(
                static fn (array $g) => $g['xg_buildup'] / $g['passes'],
                array_filter($buildUp, static fn (array $g) => $g['count'] >= $model->minCalibrationPlayers && $g['passes'] > 0),
            ),
        ];
    }

    /**
     * Team totals from every player of the team (only providers trusted or extrapolated).
     *
     * @param list<ScoringCandidate> $candidates
     * @param Calibration            $calibration
     *
     * @return array<string, array{xg: float, xa: float, xg_buildup: float, defensive_actions: float, psxg_faced: float, gk_minutes: float, league: ?string}>
     */
    private function teams(ImpactModel $model, array $candidates, array $calibration): array
    {
        $teams = [];
        foreach ($candidates as $c) {
            $key = self::teamKey($c);
            if ($key === null) {
                continue;
            }
            $teams[$key] ??= ['xg' => 0.0, 'xa' => 0.0, 'xg_buildup' => 0.0, 'defensive_actions' => 0.0, 'psxg_faced' => 0.0, 'gk_minutes' => 0.0, 'league' => $c->league];
            [$npxg, $xa, $buildUp] = self::attack($model, $c, $calibration);
            $teams[$key]['xg'] += $npxg ?? 0.0;
            $teams[$key]['xa'] += $xa ?? 0.0;
            $teams[$key]['xg_buildup'] += $buildUp ?? 0.0;
            if ($c->positionGroup === 'GK') {
                $conceded = $c->stats->value('goals_conceded');
                $prevented = $c->stats->value('goals_prevented');
                // Only full-season goalkeeping data: the team rate is taken over the minutes it covers.
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

    /**
     * Errors leading to a goal over his minutes, shrunk toward the role's rate per 90:
     * expected + (errors − expected) × matches / (matches + prior). Unknown: the role's rate.
     */
    private static function errors(ImpactModel $model, PlayerCompetitionStats $s, float $ratePer90): float
    {
        $matches = $s->getMinutes() / 90;
        $expected = $ratePer90 * $matches;
        $errors = self::seasonTotal($s, 'errors_leading_to_goal');

        return $errors === null ? $expected : $expected + ($errors - $expected) * $matches / ($matches + $model->errorPriorMatches);
    }

    /**
     * Errors leading to a goal per 90 per role, over the outfield players whose count is known.
     *
     * @param list<ScoringCandidate> $scored
     *
     * @return array<string, float>
     */
    private static function errorRates(array $scored): array
    {
        $sums = [];
        foreach ($scored as $c) {
            $errors = self::seasonTotal($c->stats, 'errors_leading_to_goal');
            if ($errors !== null && $c->positionGroup !== null) {
                $sums[$c->positionGroup]['errors'] = ($sums[$c->positionGroup]['errors'] ?? 0.0) + $errors;
                $sums[$c->positionGroup]['minutes'] = ($sums[$c->positionGroup]['minutes'] ?? 0.0) + $c->stats->getMinutes();
            }
        }

        return array_map(static fn (array $s) => $s['minutes'] > 0 ? $s['errors'] / $s['minutes'] * 90 : 0.0, $sums);
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
