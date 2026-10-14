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
    private const MINUTES = 10000.0;

    public function testTheSensitivityToPossessionIsFittedFromTheTeams(): void
    {
        // Clearances follow exposure^1.2, tackles do not depend on possession at all.
        $passes = ['A' => 600.0, 'B' => 450.0, 'C' => 300.0, 'D' => 250.0];
        $exposure = self::exposure($passes);
        $candidates = [];
        foreach ($passes as $team => $p) {
            $candidates[] = $this->candidate($team, ['accurate_passes' => $p, 'clearances' => 100 * $exposure[$team] ** 1.2, 'tackles_won' => 50.0]);
        }

        $adjustment = PossessionAdjustment::fit($candidates, ['clearances', 'tackles_won']);

        self::assertEqualsWithDelta(1.2, $adjustment->elasticities()['clearances'], 1e-9);
        self::assertEqualsWithDelta(0.0, $adjustment->elasticities()['tackles_won'], 1e-9);
        // After the adjustment every team clears equally often.
        foreach ($candidates as $c) {
            self::assertEqualsWithDelta(
                100 * $exposure['A'] ** 1.2 * $adjustment->factor($candidates[0], 'clearances'),
                (float) $c->stats->value('clearances') * $adjustment->factor($c, 'clearances'),
                1e-6,
            );
        }
        self::assertGreaterThan(1.0, $adjustment->factor($candidates[0], 'clearances'), 'the dominant team defends less: scaled up');
    }

    public function testUnknownTeamsAndFieldsAreNotAdjusted(): void
    {
        $candidates = [$this->candidate('A', ['accurate_passes' => 500.0, 'clearances' => 80.0]), $this->candidate('B', ['accurate_passes' => 300.0, 'clearances' => 120.0])];
        $adjustment = PossessionAdjustment::fit($candidates, ['clearances']);

        self::assertSame(1.0, $adjustment->factor($this->candidate('Elsewhere', []), 'clearances'));
        self::assertSame(1.0, $adjustment->factor($candidates[0], 'interceptions'));
    }

    public function testTeamsWithTooFewMinutesAreNotEstimated(): void
    {
        $candidates = [$this->candidate('A', ['accurate_passes' => 500.0, 'clearances' => 80.0], minutes: 900.0), $this->candidate('B', ['accurate_passes' => 300.0, 'clearances' => 120.0])];

        self::assertSame(1.0, PossessionAdjustment::fit($candidates, ['clearances'])->factor($candidates[0], 'clearances'));
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
    private function candidate(string $team, array $values, float $minutes = self::MINUTES): ScoringCandidate
    {
        $season = new PlayerSeason(new Player("Player {$team}"), new Season('2025/26', new \DateTimeImmutable(), new \DateTimeImmutable()));
        $stats = new PlayerCompetitionStats(
            $season,
            StatsScope::League,
            new ImportBatch('test', 'test', '2025/26', 'test', '1'),
            $minutes,
            $values,
            array_map(static fn (string $f) => FieldCatalog::get($f)->origin->value, array_combine(array_keys($values), array_keys($values))),
            ['SofaScore' => ['status' => 'ok', 'minutes' => $minutes, 'reason' => null]],
        );

        return new ScoringCandidate(crc32($team), "Player {$team}", 'DF', $team, $stats, 'PL');
    }
}
