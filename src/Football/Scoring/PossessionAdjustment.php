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
 *   over that plus the average of its league, p = passes / (passes + league average).
 * - How strongly each statistic depends on possession is fitted from the data, not assumed:
 *   β = slope of ln(team rate / league average rate) on ln((1 − p) / (1 − league average p))
 *   over all teams (the full adjustment, β = 1, overcorrects tackles and interceptions).
 * - Adjusted value = value × ((1 − p) / (1 − league average p)) ^ −β: a player of a team that
 *   is often without the ball is scaled down, one of a dominant team up.
 */
final class PossessionAdjustment
{
    /** Teams with fewer SofaScore outfield minutes (about 10 matches) are not estimated. */
    public const MIN_TEAM_MINUTES = 9900.0;

    /** Outfield players on the pitch: team rates are per 90 team minutes. */
    private const OUTFIELD = 10;

    /**
     * @param array<string, float> $exposure   team key => (1 − p) / (1 − league average p)
     * @param array<string, float> $elasticity field => β
     */
    private function __construct(
        private readonly array $exposure,
        private readonly array $elasticity,
    ) {
    }

    /**
     * @param list<ScoringCandidate> $candidates all players of the scope (team totals)
     * @param list<string>           $fields     the statistics to fit an elasticity for
     */
    public static function fit(array $candidates, array $fields): self
    {
        // Team totals over outfield players with full-season SofaScore data.
        $teams = [];
        foreach ($candidates as $c) {
            $s = $c->stats;
            if ($c->team === null || $c->league === null || $c->positionGroup === 'GK' || $s->originStatus(Origin::Sofascore) !== OriginStatus::Ok) {
                continue;
            }
            $key = self::key($c);
            $teams[$key] ??= ['league' => $c->league, 'minutes' => 0.0, 'passes' => 0.0, 'fields' => array_fill_keys($fields, 0.0)];
            $teams[$key]['minutes'] += (float) $s->originMinutes(Origin::Sofascore);
            $teams[$key]['passes'] += $s->value('accurate_passes') ?? 0.0;
            foreach ($fields as $field) {
                $teams[$key]['fields'][$field] += $s->value($field) ?? 0.0;
            }
        }
        $teams = array_filter($teams, static fn (array $t) => $t['minutes'] >= self::MIN_TEAM_MINUTES);

        $rate = static fn (array $t, float $total) => $total / $t['minutes'] * 90 * self::OUTFIELD;
        $leaguePasses = self::leagueMeans(array_map(static fn (array $t) => ['league' => $t['league'], 'value' => $rate($t, $t['passes'])], $teams));
        $possession = array_map(static fn (array $t) => $rate($t, $t['passes']) / ($rate($t, $t['passes']) + $leaguePasses[$t['league']]), $teams);
        $leaguePossession = self::leagueMeans(array_map(static fn (array $t, float $p) => ['league' => $t['league'], 'value' => $p], $teams, $possession));
        $exposure = [];
        foreach ($teams as $key => $t) {
            $exposure[$key] = (1 - $possession[$key]) / (1 - $leaguePossession[$t['league']]);
        }

        $elasticity = [];
        foreach ($fields as $field) {
            $leagueRate = self::leagueMeans(array_map(static fn (array $t) => ['league' => $t['league'], 'value' => $rate($t, $t['fields'][$field])], $teams));
            $x = [];
            $y = [];
            foreach ($teams as $key => $t) {
                $value = $rate($t, $t['fields'][$field]);
                if ($value > 0 && $leagueRate[$t['league']] > 0) {
                    $x[] = log($exposure[$key]);
                    $y[] = log($value / $leagueRate[$t['league']]);
                }
            }
            $elasticity[$field] = self::slope($x, $y);
        }

        return new self($exposure, $elasticity);
    }

    /** Multiplier for a player's per-90 count of $field: 1.0 when his team or the field is unknown. */
    public function factor(ScoringCandidate $c, string $field): float
    {
        $exposure = $c->team === null ? null : ($this->exposure[self::key($c)] ?? null);
        $beta = $this->elasticity[$field] ?? null;

        return $exposure === null || $beta === null ? 1.0 : $exposure ** -$beta;
    }

    /** @return array<string, float> field => β, for reports */
    public function elasticities(): array
    {
        return $this->elasticity;
    }

    private static function key(ScoringCandidate $c): string
    {
        return $c->league . '|' . $c->team;
    }

    /**
     * @param array<array-key, array{league: string, value: float}> $rows
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
        $n = count($x);
        if ($n < 2) {
            return 0.0;
        }
        $mx = array_sum($x) / $n;
        $my = array_sum($y) / $n;
        $cov = 0.0;
        $var = 0.0;
        foreach ($x as $i => $v) {
            $cov += ($v - $mx) * ($y[$i] - $my);
            $var += ($v - $mx) ** 2;
        }

        return $var > 0 ? $cov / $var : 0.0;
    }
}
