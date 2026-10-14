<?php

declare(strict_types=1);

namespace App\Football\Scoring;

use App\Football\Data\Origin;
use App\Football\Data\OriginStatus;

/**
 * Defensive actions depend on how much a team is without the ball: a team with little
 * possession clears, blocks and wins aerial duels far more often. This adjusts a player's
 * per-90 count for his team's possession. Pure and deterministic.
 *
 * - Possession (not in the data) is estimated from passing: a team's accurate passes per 90
 *   (outfield players) over that plus the average of its league, p = passes / (passes + league average).
 * - How strongly each statistic depends on possession is fitted from the data, per position,
 *   not assumed: β = slope of ln(rate / league average rate) on ln((1 − p) / (1 − league
 *   average p)), over the teams, with the rate of that team's players in that position
 *   (pooled over all outfield players, the fit overcorrected defenders).
 * - Adjusted value = value × ((1 − p) / (1 − league average p)) ^ −β: a player of a team that
 *   is often without the ball is scaled down for statistics that rise without it (β > 0), up
 *   for those that rise with it (β < 0).
 *
 * A player who moved clubs gets the factor of his main club.
 */
final class PossessionAdjustment
{
    /** Teams with fewer SofaScore outfield minutes (about 10 matches) are not estimated. */
    public const MIN_TEAM_MINUTES = 9900.0;

    /** A team's players in a position need this many minutes to count in that position's fit. */
    public const MIN_ROLE_MINUTES = 900.0;

    /**
     * @param array<string, float>                                                    $exposure team key => (1 − p) / (1 − league average p)
     * @param array<string, array<string, array{beta: float, r: ?float, teams: int}>> $fits     role => field => β, the correlation it removes, teams fitted on
     */
    private function __construct(
        private readonly array $exposure,
        private readonly array $fits,
    ) {
    }

    /**
     * @param list<ScoringCandidate>      $candidates   all players of the scope (team totals)
     * @param array<string, list<string>> $fieldsByRole position group => the statistics to fit
     */
    public static function fit(array $candidates, array $fieldsByRole): self
    {
        /** @var array<string, array{league: string, minutes: float, passes: float, roles: array<string, array{minutes: float, fields: array<string, float>}>}> $teams */
        $teams = [];
        foreach ($candidates as $c) {
            $s = $c->stats;
            $group = $c->positionGroup;
            if ($c->team === null || $c->league === null || $group === null || $s->originStatus(Origin::Sofascore) !== OriginStatus::Ok) {
                continue;
            }
            $key = self::key($c);
            $minutes = (float) $s->originMinutes(Origin::Sofascore);
            $teams[$key] ??= ['league' => $c->league, 'minutes' => 0.0, 'passes' => 0.0, 'roles' => []];
            if ($group !== 'GK') {
                $teams[$key]['minutes'] += $minutes;
                $teams[$key]['passes'] += $s->value('accurate_passes') ?? 0.0;
            }
            $teams[$key]['roles'][$group] ??= ['minutes' => 0.0, 'fields' => []];
            $teams[$key]['roles'][$group]['minutes'] += $minutes;
            foreach ($fieldsByRole[$group] ?? [] as $field) {
                $teams[$key]['roles'][$group]['fields'][$field] = ($teams[$key]['roles'][$group]['fields'][$field] ?? 0.0) + ($s->value($field) ?? 0.0);
            }
        }
        $teams = array_filter($teams, static fn (array $t) => $t['minutes'] >= self::MIN_TEAM_MINUTES);

        $passRate = array_map(static fn (array $t) => $t['passes'] / $t['minutes'], $teams);
        $leaguePasses = self::leagueMeans(array_map(static fn (array $t, float $r) => ['league' => $t['league'], 'value' => $r], $teams, $passRate));
        $possession = [];
        foreach ($teams as $key => $t) {
            $possession[$key] = $passRate[$key] / ($passRate[$key] + $leaguePasses[$t['league']]);
        }
        $leaguePossession = self::leagueMeans(array_map(static fn (array $t, float $p) => ['league' => $t['league'], 'value' => $p], $teams, $possession));
        $exposure = [];
        foreach ($teams as $key => $t) {
            $exposure[$key] = (1 - $possession[$key]) / (1 - $leaguePossession[$t['league']]);
        }

        $fits = [];
        foreach ($fieldsByRole as $role => $fields) {
            $inRole = array_filter($teams, static fn (array $t) => ($t['roles'][$role]['minutes'] ?? 0.0) >= self::MIN_ROLE_MINUTES);
            foreach ($fields as $field) {
                $rates = array_map(static fn (array $t) => ($t['roles'][$role]['fields'][$field] ?? 0.0) / $t['roles'][$role]['minutes'], $inRole);
                $leagueRate = self::leagueMeans(array_map(static fn (array $t, float $r) => ['league' => $t['league'], 'value' => $r], $inRole, $rates));
                $x = [];
                $y = [];
                foreach ($inRole as $key => $t) {
                    if ($rates[$key] > 0 && $leagueRate[$t['league']] > 0) {
                        $x[] = log($exposure[$key]);
                        $y[] = log($rates[$key] / $leagueRate[$t['league']]);
                    }
                }
                $fits[$role][$field] = ['beta' => self::slope($x, $y), 'r' => self::correlation($x, $y), 'teams' => count($x)];
            }
        }

        return new self($exposure, $fits);
    }

    /** Multiplier for a player's per-90 count of $field: 1.0 when his team, position or the field is unknown. */
    public function factor(ScoringCandidate $c, string $field): float
    {
        $exposure = $c->team === null ? null : ($this->exposure[self::key($c)] ?? null);
        $beta = $this->fits[(string) $c->positionGroup][$field]['beta'] ?? null;

        return $exposure === null || $beta === null ? 1.0 : $exposure ** -$beta;
    }

    /**
     * For reports: per position and statistic, β, the correlation between the team rate and
     * how much the team is without the ball (what the adjustment removes), and the teams fitted on.
     *
     * @return array<string, array<string, array{beta: float, r: ?float, teams: int}>>
     */
    public function fits(): array
    {
        return $this->fits;
    }

    private static function key(ScoringCandidate $c): string
    {
        return $c->league . '|' . $c->team;
    }

    /**
     * @param list<array{league: string, value: float}> $rows
     *
     * @return array<string, float>
     */
    private static function leagueMeans(array $rows): array
    {
        $sums = [];
        foreach ($rows as $row) {
            $sums[$row['league']][] = $row['value'];
        }

        return array_map(static fn (array $v) => array_sum($v) / count($v), $sums);
    }

    /**
     * @param list<float> $x
     * @param list<float> $y
     */
    private static function slope(array $x, array $y): float
    {
        [$cov, $varX] = self::moments($x, $y);

        return $varX > 0 ? $cov / $varX : 0.0;
    }

    /**
     * @param list<float> $x
     * @param list<float> $y
     */
    private static function correlation(array $x, array $y): ?float
    {
        [$cov, $varX, $varY] = self::moments($x, $y);

        return $varX > 0 && $varY > 0 ? $cov / sqrt($varX * $varY) : null;
    }

    /**
     * Sums of co-deviations: Σ(x − x̄)(y − ȳ), Σ(x − x̄)², Σ(y − ȳ)².
     *
     * @param list<float> $x
     * @param list<float> $y
     *
     * @return array{float, float, float}
     */
    private static function moments(array $x, array $y): array
    {
        $n = count($x);
        if ($n < 2) {
            return [0.0, 0.0, 0.0];
        }
        $mx = array_sum($x) / $n;
        $my = array_sum($y) / $n;
        $cov = 0.0;
        $varX = 0.0;
        $varY = 0.0;
        foreach ($x as $i => $v) {
            $cov += ($v - $mx) * ($y[$i] - $my);
            $varX += ($v - $mx) ** 2;
            $varY += ($y[$i] - $my) ** 2;
        }

        return [$cov, $varX, $varY];
    }
}
