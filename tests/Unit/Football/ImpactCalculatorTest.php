<?php

declare(strict_types=1);

namespace App\Tests\Unit\Football;

use App\Entity\ImportBatch;
use App\Entity\Player;
use App\Entity\PlayerCompetitionStats;
use App\Entity\PlayerSeason;
use App\Entity\Season;
use App\Entity\StatsScope;
use App\Football\Data\FieldCatalog;
use App\Football\Scoring\Impact\ImpactCalculator;
use App\Football\Scoring\Impact\ImpactModel;
use App\Football\Scoring\ScoringCandidate;
use PHPUnit\Framework\TestCase;

final class ImpactCalculatorTest extends TestCase
{
    public function testTheTeamsExpectedGoalsAreSharedOutExactlyOnce(): void
    {
        $striker = $this->player(1, 'FW', ['np_xg' => 10.0, 'xa' => 0.0, 'xg_buildup' => 2.0]);
        $playmaker = $this->player(2, 'MF', ['np_xg' => 0.0, 'xa' => 6.0, 'xg_buildup' => 6.0]);

        $result = (new ImpactCalculator())->calculate(new ImpactModel(), [$striker, $playmaker], [$striker, $playmaker]);

        // shooter 50% of 10, passer 30% of 6; build-up pool = 20% × 10 + 30% × (10 − 6) = 3.2, shared 2 : 6.
        self::assertEqualsWithDelta(5.0, $result[1]['goals']['shooting'], 1e-9);
        self::assertEqualsWithDelta(1.8, $result[2]['goals']['creation'], 1e-9);
        self::assertEqualsWithDelta(0.8, $result[1]['goals']['build_up'], 1e-9);
        self::assertEqualsWithDelta(2.4, $result[2]['goals']['build_up'], 1e-9);
        $attack = static fn (array $r) => $r['goals']['shooting'] + $r['goals']['creation'] + $r['goals']['build_up'];
        self::assertEqualsWithDelta(10.0, $attack($result[1]) + $attack($result[2]), 1e-9, 'credits add up to the team\'s xG');
    }

    public function testFinishingIsShrunkByShots(): void
    {
        $few = $this->player(1, 'FW', ['np_xg' => 2.0, 'np_goals' => 6.0, 'shots' => 20.0]);
        $many = $this->player(2, 'FW', ['np_xg' => 20.0, 'np_goals' => 24.0, 'shots' => 120.0]);

        $result = (new ImpactCalculator())->calculate(new ImpactModel(), [$few, $many], [$few, $many]);

        // both +4 goals; shooter's share 0.5; × shots / (shots + 60)
        self::assertEqualsWithDelta(0.5 * 4 * 20 / 80, $result[1]['goals']['finishing'], 1e-9);
        self::assertEqualsWithDelta(0.5 * 4 * 120 / 180, $result[2]['goals']['finishing'], 1e-9);
    }

    public function testGoalsPreventedIsShrunkByShotsOnTargetFaced(): void
    {
        $keeper = $this->player(1, 'GK', ['goals_prevented' => 10.0, 'saves' => 100.0, 'goals_conceded' => 50.0]);

        $result = (new ImpactCalculator())->calculate(new ImpactModel(), [$keeper], [$keeper]);

        self::assertEqualsWithDelta(10.0 * 150 / (150 + 300), $result[1]['goals']['goalkeeping'], 1e-9);
    }

    public function testTeamDefenceIsSharedByDefensiveActions(): void
    {
        // League: two teams, keepers with full seasons; team A concedes 10 fewer expected goals on target than team B.
        $model = new ImpactModel(minTeamGoalkeeperMinutes: 900.0);
        $keeperA = $this->player(1, 'GK', ['goals_conceded' => 20.0, 'goals_prevented' => 0.0, 'saves' => 60.0], team: 'A');
        $keeperB = $this->player(2, 'GK', ['goals_conceded' => 30.0, 'goals_prevented' => 0.0, 'saves' => 60.0], team: 'B');
        $defender = $this->player(3, 'DF', ['tackles_won' => 30.0, 'interceptions' => 30.0, 'blocks' => 0.0, 'clearances' => 0.0, 'aerial_duels_won' => 0.0], team: 'A');
        $midfielder = $this->player(4, 'MF', ['tackles_won' => 10.0, 'interceptions' => 10.0, 'blocks' => 0.0, 'clearances' => 0.0, 'aerial_duels_won' => 0.0], team: 'A');
        $all = [$keeperA, $keeperB, $defender, $midfielder];

        $result = (new ImpactCalculator())->calculate($model, $all, $all);

        // Per 90 over the 2000 covered minutes: A 0.9, B 1.35, league average 1.125. Over a 38-match
        // Premier League season A prevents (1.125 − 0.9) × 38 = 8.55 goals, shared 60 : 20.
        self::assertEqualsWithDelta(8.55 * 0.75, $result[3]['goals']['defence'], 1e-9);
        self::assertEqualsWithDelta(8.55 * 0.25, $result[4]['goals']['defence'], 1e-9);
        self::assertNull($result[1]['goals']['defence'], 'keepers are measured by goals prevented instead');
    }

    public function testUnderstatGapsFallBackToSofascore(): void
    {
        // No Understat record; SofaScore has 9.0 xG including 3 penalties, and 2.5 xA.
        $player = $this->player(1, 'FW', ['xg_sofascore' => 9.0, 'penalties_taken' => 3.0, 'xa_sofascore' => 2.5], understat: 'missing');

        $result = (new ImpactCalculator())->calculate(new ImpactModel(), [$player], [$player]);

        self::assertEqualsWithDelta(0.5 * (9.0 - 3 * 0.76), $result[1]['goals']['shooting'], 1e-9);
        self::assertEqualsWithDelta(0.3 * 2.5, $result[1]['goals']['creation'], 1e-9);
        self::assertSame('SofaScore', $result[1]['attack_source']);
    }

    public function testReplacementLevelComesFromTheReferencePlayers(): void
    {
        $players = array_map(fn (int $i) => $this->player($i, 'FW', ['np_xg' => (float) $i]), range(1, 5));

        $all = (new ImpactCalculator())->calculate(new ImpactModel(), $players, $players);
        $topOnly = (new ImpactCalculator())->calculate(new ImpactModel(), $players, $players, reference: [4, 5]);

        self::assertGreaterThan($all[5]['replacement'], $topOnly[5]['replacement']);
    }

    public function testContributionIsMeasuredAboveTheRolesReplacementLevel(): void
    {
        $players = array_map(fn (int $i) => $this->player($i, 'FW', ['np_xg' => (float) $i]), range(1, 5));

        $result = (new ImpactCalculator())->calculate(new ImpactModel(), $players, $players);

        // 20th percentile of [0.5 .. 2.5] per season (all play 2000 minutes) = 1.3 → per 90 × 2000/90
        $level = $result[5]['replacement'];
        self::assertEqualsWithDelta(0.5 * 1.8, $level, 1e-9);
        self::assertEqualsWithDelta(2.5 - $level, $result[5]['above'], 1e-9);
    }

    public function testWithoutAttackingDataTheContributionIsUnknownNotZero(): void
    {
        $player = $this->player(1, 'FW', [], understat: 'missing', sofascore: 'missing');

        $result = (new ImpactCalculator())->calculate(new ImpactModel(), [$player], [$player]);

        self::assertNull($result[1]['total']);
        self::assertNull($result[1]['above']);
    }

    public function testAPartialProviderIsExtrapolatedFromItsRate(): void
    {
        // Understat covers 1000 of his 2000 minutes (a transfer between leagues).
        $player = $this->player(1, 'FW', ['np_xg' => 5.0], understat: 'partial', understatMinutes: 1000.0);

        $result = (new ImpactCalculator())->calculate(new ImpactModel(), [$player], [$player]);

        self::assertEqualsWithDelta(0.5 * 10.0, $result[1]['goals']['shooting'], 1e-9);
    }

    public function testSharesMustAddUpToOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ImpactModel(shooterShare: 0.6);
    }

    /** @param array<string, float> $values */
    private function player(int $id, string $group, array $values, string $team = 'Club', string $understat = 'ok', float $understatMinutes = 2000.0, string $sofascore = 'ok'): ScoringCandidate
    {
        $values += ['np_xg' => 0.0, 'xa' => 0.0, 'xg_buildup' => 0.0];
        if ($understat === 'missing') {
            unset($values['np_xg'], $values['xa'], $values['xg_buildup']);
        }
        $season = new PlayerSeason(new Player("P{$id}"), new Season('2025/26', new \DateTimeImmutable(), new \DateTimeImmutable()));
        $stats = new PlayerCompetitionStats(
            $season,
            StatsScope::League,
            new ImportBatch('test', 'test', '2025/26', 'test', '1'),
            2000.0,
            $values,
            array_map(static fn (string $f) => FieldCatalog::get($f)->origin->value, array_combine(array_keys($values), array_keys($values))),
            [
                'FBref' => ['status' => 'ok', 'minutes' => 2000.0, 'reason' => null],
                'Understat' => ['status' => $understat, 'minutes' => $understat === 'missing' ? null : $understatMinutes, 'reason' => null],
                'SofaScore' => ['status' => $sofascore, 'minutes' => 2000.0, 'reason' => null],
            ],
        );

        return new ScoringCandidate($id, "P{$id}", $group, $team, $stats, 'PL');
    }
}
