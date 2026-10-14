<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\ImportBatch;
use App\Entity\Player;
use App\Entity\PlayerCompetitionStats;
use App\Entity\PlayerSeason;
use App\Entity\Season;
use App\Entity\StatsScope;
use App\Football\Data\Origin;
use App\Football\Data\OriginStatus;
use PHPUnit\Framework\TestCase;

final class PlayerCompetitionStatsTest extends TestCase
{
    public function testPer90UsesTheSupplyingProvidersMinutes(): void
    {
        $stats = $this->stats(
            ['goals' => 9.0, 'tackles' => 3.0],
            ['goals' => 'FBref', 'tackles' => 'SofaScore'],
            ['FBref' => ['ok', 1800.0], 'SofaScore' => ['partial', 900.0]],
        );

        self::assertSame(0.45, $stats->per90('goals'));
        self::assertEqualsWithDelta(0.3, $stats->per90('tackles'), 1e-9, '3 tackles in SofaScore\'s 900 minutes, not in 1800');
        self::assertTrue($stats->trusted('goals'));
        self::assertFalse($stats->trusted('tackles'));
    }

    public function testTooFewProviderMinutesGiveNoPer90(): void
    {
        $stats = $this->stats(['tackles' => 3.0], ['tackles' => 'SofaScore'], ['FBref' => ['ok', 1882.0], 'SofaScore' => ['partial', 90.0]]);

        self::assertNull($stats->per90('tackles'), '90 minutes is not a rate');
        self::assertSame(3.0, $stats->value('tackles'));
    }

    public function testDerivedFieldNeedsEnoughScopeMinutesForPer90(): void
    {
        $stats = $this->stats(['np_goals' => 2.0], ['np_goals' => 'derived'], ['FBref' => ['ok', 300.0], 'SofaScore' => ['ok', 300.0]], minutes: 300.0);

        self::assertNull($stats->per90('np_goals'));
    }

    public function testSuspectOrMissingProviderGivesNoPer90(): void
    {
        $stats = $this->stats(['tackles' => 30.0], ['tackles' => 'SofaScore'], ['FBref' => ['ok', 1800.0], 'SofaScore' => ['suspect', 1500.0]]);

        self::assertNull($stats->per90('tackles'));
        self::assertSame(OriginStatus::Suspect, $stats->fieldStatus('tackles'));
    }

    public function testUnknownValueIsNullNotZero(): void
    {
        $stats = $this->stats([], [], ['FBref' => ['ok', 1800.0]]);

        self::assertNull($stats->value('xg'));
        self::assertNull($stats->per90('xg'));
        self::assertSame(OriginStatus::Missing, $stats->fieldStatus('xg'), 'no Understat check means missing');
    }

    public function testDerivedFieldTakesTheWeakestInput(): void
    {
        $stats = $this->stats(
            ['np_goals' => 7.0],
            ['np_goals' => 'derived'],
            ['FBref' => ['ok', 1800.0], 'SofaScore' => ['partial', 900.0]],
        );

        self::assertSame(OriginStatus::Partial, $stats->fieldStatus('np_goals'));
        self::assertSame(0.35, $stats->per90('np_goals'), 'derived fields use the scope minutes');
    }

    public function testNoPer90ForMinutesOrPercentages(): void
    {
        $stats = $this->stats(['minutes' => 1800.0, 'pass_accuracy' => 88.5], ['minutes' => 'FBref', 'pass_accuracy' => 'SofaScore'], ['FBref' => ['ok', 1800.0], 'SofaScore' => ['ok', 1800.0]]);

        self::assertNull($stats->per90('minutes'));
        self::assertNull($stats->per90('pass_accuracy'));
        self::assertSame(88.5, $stats->value('pass_accuracy'));
    }

    public function testOriginMinutesAndReason(): void
    {
        $stats = $this->stats([], [], ['SofaScore' => ['partial', 90.0]]);

        self::assertSame(90.0, $stats->originMinutes(Origin::Sofascore));
        self::assertNull($stats->originMinutes(Origin::Understat));
        self::assertSame('reason', $stats->originReason(Origin::Sofascore));
    }

    /**
     * @param array<string, float>                 $values
     * @param array<string, string>                $origins
     * @param array<string, array{string, ?float}> $checks  origin => [status, minutes]
     */
    private function stats(array $values, array $origins, array $checks, float $minutes = 1800.0): PlayerCompetitionStats
    {
        $season = new PlayerSeason(new Player('Test'), new Season('2025/26', new \DateTimeImmutable(), new \DateTimeImmutable()));

        return new PlayerCompetitionStats(
            $season,
            StatsScope::League,
            new ImportBatch('test', 'test', '2025/26', 'test', '1'),
            $minutes,
            $values,
            $origins,
            array_map(static fn (array $c) => ['status' => $c[0], 'minutes' => $c[1], 'reason' => 'reason'], $checks),
        );
    }
}
