<?php

declare(strict_types=1);

namespace App\Tests\Unit\Football;

use App\Entity\ImportBatch;
use App\Entity\Player;
use App\Entity\PlayerCompetitionStats;
use App\Entity\PlayerSeason;
use App\Entity\Season;
use App\Entity\StatsScope;
use App\Entity\StatsScope as Scope;
use App\Football\Data\FieldCatalog;
use App\Football\Scoring\Component;
use App\Football\Scoring\Metric;
use App\Football\Scoring\MetricMode;
use App\Football\Scoring\MetricValue;
use App\Football\Scoring\ScoreCalculator;
use App\Football\Scoring\ScoringCandidate;
use App\Football\Scoring\ScoringModel;
use App\Football\Standards\StatisticCatalog;
use App\Football\Standards\Stats;
use PHPUnit\Framework\TestCase;

/** How the derived standards are applied: rates from counts, proxies, possession, clustering. */
final class StandardsEngineTest extends TestCase
{
    public function testGroundDuelSuccessSubtractsAerialDuelsFromAllDuelsLost(): void
    {
        $stats = $this->stats(['ground_duels_won' => 30.0, 'duels_lost' => 40.0, 'aerial_duels_lost' => 10.0]);

        self::assertSame([0.5, 60.0], StatisticCatalog::get('ground_duel_success')->measure($stats));
        $metric = new Metric('g', 'G', ['ground_duels_won'], MetricMode::Ratio, 1.0, sampleFields: ['ground_duels_won', 'duels_lost', '-aerial_duels_lost']);
        self::assertSame(0.5, MetricValue::of($metric, $stats));
        self::assertSame(60.0, MetricValue::sample($metric, $stats));
    }

    public function testAProxyFillsInWhenTheProviderHasNoRecord(): void
    {
        // No Understat xGBuildup: estimated from accurate passes (900 in 1800 minutes = 45 per 90) × the derived scale.
        $stats = $this->stats(['accurate_passes' => 900.0]);
        $statistic = StatisticCatalog::get('xg_buildup');

        self::assertNull($statistic->measure($stats));
        self::assertEqualsWithDelta(45 * 0.01, $statistic->measure($stats, 0.01)[0] ?? null, 1e-12);
        $metric = new Metric('b', 'B', ['xg_buildup'], MetricMode::Per90, 1.0, proxy: $statistic->proxy, proxyScale: 0.01);
        self::assertEqualsWithDelta(0.45, MetricValue::of($metric, $stats), 1e-12);
        self::assertSame(20.0, MetricValue::sample($metric, $stats), 'the proxy\'s own matches');
    }

    public function testThePossessionPartTheStandardsFittedIsRemoved(): void
    {
        // Two teams, the same passes per 90; one has more of the ball. With a slope of 10 per unit of
        // possession share, the dominant team's player is adjusted down and they end up level.
        $model = new ScoringModel('test', '1', 'Test', '', Scope::League, 900, ['FW'], 0.0, [
            new Component('c', 'C', '', 1.0, [new Metric('passes', 'Passes', ['accurate_passes'], MetricMode::Per90, 1.0, possessionSlope: 0.0, possessionMean: 0.5)]),
        ]);
        $raw = (new ScoreCalculator())->score($model, [$this->candidate(1, 'A', 1200.0), $this->candidate(2, 'B', 600.0)]);
        self::assertNotSame($raw[0]->score, $raw[1]->score);

        $teamA = $this->candidate(1, 'A', 1200.0);
        $teamB = $this->candidate(2, 'B', 600.0);
        $p = \App\Football\Scoring\PossessionAdjustment::possession([$teamA, $teamB]);
        $slope = (1200.0 - 600.0) / 10000 * 90 / ($p['PL|A'] - $p['PL|B']); // per-90 difference over the possession difference
        $adjusted = new ScoringModel('test', '1', 'Test', '', Scope::League, 900, ['FW'], 0.0, [
            new Component('c', 'C', '', 1.0, [new Metric('passes', 'Passes', ['accurate_passes'], MetricMode::Per90, 1.0, possessionSlope: $slope, possessionMean: 0.5)]),
        ]);
        $results = (new ScoreCalculator())->score($adjusted, [$teamA, $teamB]);
        self::assertEqualsWithDelta($results[0]->score, $results[1]->score, 1e-9);
    }

    public function testStatisticsThatMeasureTheSameThingAreGrouped(): void
    {
        $a = [1 => 1.0, 2 => 2.0, 3 => 3.0, 4 => 4.0];
        $groups = Stats::cluster([
            'a' => $a,
            'b' => [1 => 1.1, 2 => 2.1, 3 => 2.9, 4 => 4.2],
            'c' => [1 => 3.0, 2 => 1.0, 3 => 4.0, 4 => 2.0],
        ], 0.8);

        self::assertSame([['a', 'b'], ['c']], $groups);
    }

    private function candidate(int $id, string $team, float $passes): ScoringCandidate
    {
        $stats = $this->stats(['accurate_passes' => $passes], 10000.0);

        return new ScoringCandidate($id, $team, 'FW', $team, $stats, 'PL');
    }

    /** @param array<string, float> $values */
    private function stats(array $values, float $minutes = 1800.0): PlayerCompetitionStats
    {
        $season = new PlayerSeason(new Player('Test'), new Season('2025/26', new \DateTimeImmutable(), new \DateTimeImmutable()));

        return new PlayerCompetitionStats(
            $season,
            StatsScope::League,
            new ImportBatch('test', 'test', '2025/26', 'test', '1'),
            $minutes,
            $values,
            array_map(static fn (string $f) => FieldCatalog::get($f)->origin->value, array_combine(array_keys($values), array_keys($values))),
            ['FBref' => ['status' => 'ok', 'minutes' => $minutes, 'reason' => null], 'SofaScore' => ['status' => 'ok', 'minutes' => $minutes, 'reason' => null], 'Understat' => ['status' => 'missing', 'minutes' => null, 'reason' => null]],
        );
    }
}
