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
use App\Football\Scoring\PossessionAdjustment;
use App\Football\Scoring\ScoringCandidate;
use PHPUnit\Framework\TestCase;

final class PossessionAdjustmentTest extends TestCase
{
    private const PASSES = ['A' => 600.0, 'B' => 450.0, 'C' => 300.0, 'D' => 250.0];

    public function testTheSensitivityIsFittedPerPosition(): void
    {
        // Defenders' clearances follow exposure^1.2; midfielders' tackles do not depend on possession.
        $exposure = self::exposure(self::PASSES);
        $defenders = [];
        $midfielders = [];
        foreach (self::PASSES as $team => $passes) {
            $defenders[$team] = $this->candidate($team, 'DF', ['accurate_passes' => $passes / 2, 'clearances' => 100 * $exposure[$team] ** 1.2]);
            $midfielders[$team] = $this->candidate($team, 'MF', ['accurate_passes' => $passes / 2, 'tackles_won' => 50.0]);
        }
        $candidates = [...array_values($defenders), ...array_values($midfielders)];

        $adjustment = PossessionAdjustment::fit($candidates, ['DF' => ['clearances'], 'MF' => ['tackles_won']]);

        self::assertEqualsWithDelta(1.2, $adjustment->fits()['DF']['clearances']['beta'], 1e-9);
        self::assertEqualsWithDelta(1.0, $adjustment->fits()['DF']['clearances']['r'], 1e-9);
        self::assertEqualsWithDelta(0.0, $adjustment->fits()['MF']['tackles_won']['beta'], 1e-9);
        self::assertSame(4, $adjustment->fits()['DF']['clearances']['teams']);
        // After the adjustment every team's defenders clear equally often.
        foreach ($defenders as $c) {
            self::assertEqualsWithDelta(100.0 * $exposure['A'] ** 1.2 * $adjustment->factor($defenders['A'], 'clearances'), (float) $c->stats->value('clearances') * $adjustment->factor($c, 'clearances'), 1e-6);
        }
        self::assertGreaterThan(1.0, $adjustment->factor($defenders['A'], 'clearances'), 'the dominant team defends less: scaled up');
        self::assertSame(1.0, $adjustment->factor($defenders['A'], 'tackles_won'), 'not fitted for defenders');
    }

    public function testEachLeagueIsItsOwnReference(): void
    {
        // League 2 plays the same way at double the volume: same β, and equal factors for equal standing.
        $exposure = self::exposure(self::PASSES);
        $candidates = [];
        foreach (self::PASSES as $team => $passes) {
            $candidates[] = $this->candidate($team, 'DF', ['accurate_passes' => $passes, 'clearances' => 100 * $exposure[$team]], league: 'PL');
            $candidates[] = $this->candidate("{$team}2", 'DF', ['accurate_passes' => 2 * $passes, 'clearances' => 200 * $exposure[$team]], league: 'PD');
        }

        $adjustment = PossessionAdjustment::fit($candidates, ['DF' => ['clearances']]);

        self::assertEqualsWithDelta(1.0, $adjustment->fits()['DF']['clearances']['beta'], 1e-9);
        self::assertEqualsWithDelta($adjustment->factor($candidates[0], 'clearances'), $adjustment->factor($candidates[1], 'clearances'), 1e-9);
    }

    public function testGoalkeepersDoNotCountTowardPossessionButHaveTheirOwnFit(): void
    {
        $exposure = self::exposure(self::PASSES);
        $candidates = [];
        foreach (self::PASSES as $team => $passes) {
            $candidates[] = $this->candidate($team, 'DF', ['accurate_passes' => $passes]);
            // Keepers of dominant teams pass more; their passes must not change the possession estimate.
            $candidates[] = $this->candidate($team, 'GK', ['accurate_passes' => 1000 * $exposure[$team] ** -0.5]);
        }

        $adjustment = PossessionAdjustment::fit($candidates, ['GK' => ['accurate_passes']]);

        self::assertEqualsWithDelta(-0.5, $adjustment->fits()['GK']['accurate_passes']['beta'], 1e-9);
    }

    public function testUnknownTeamsAndTeamsWithTooFewMinutesAreNotAdjusted(): void
    {
        $candidates = [
            $this->candidate('A', 'DF', ['accurate_passes' => 500.0, 'clearances' => 80.0], minutes: 900.0),
            $this->candidate('B', 'DF', ['accurate_passes' => 300.0, 'clearances' => 120.0]),
            $this->candidate('C', 'DF', ['accurate_passes' => 400.0, 'clearances' => 100.0]),
        ];
        $adjustment = PossessionAdjustment::fit($candidates, ['DF' => ['clearances']]);

        self::assertSame(1.0, $adjustment->factor($candidates[0], 'clearances'));
        self::assertSame(1.0, $adjustment->factor($this->candidate('Elsewhere', 'DF', []), 'clearances'));
    }

    /**
     * (1 − p) / (1 − league average p), with p = passes / (passes + league average passes).
     *
     * @param array<string, float> $passes
     *
     * @return array<string, float>
     */
    private static function exposure(array $passes): array
    {
        $average = array_sum($passes) / count($passes);
        $p = array_map(static fn (float $x) => $x / ($x + $average), $passes);
        $pAverage = array_sum($p) / count($p);

        return array_map(static fn (float $x) => (1 - $x) / (1 - $pAverage), $p);
    }

    /** @param array<string, float> $values */
    private function candidate(string $team, string $group, array $values, float $minutes = 10000.0, string $league = 'PL'): ScoringCandidate
    {
        $season = new PlayerSeason(new Player("{$group} {$team}"), new Season('2025/26', new \DateTimeImmutable(), new \DateTimeImmutable()));
        $stats = new PlayerCompetitionStats(
            $season,
            StatsScope::League,
            new ImportBatch('test', 'test', '2025/26', 'test', '1'),
            $minutes,
            $values,
            array_map(static fn (string $f) => FieldCatalog::get($f)->origin->value, array_combine(array_keys($values), array_keys($values))),
            ['SofaScore' => ['status' => 'ok', 'minutes' => $minutes, 'reason' => null]],
        );

        return new ScoringCandidate(crc32("{$group} {$team}"), "{$group} {$team}", $group, $team, $stats, $league);
    }
}
