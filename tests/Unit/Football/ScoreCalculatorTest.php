<?php

declare(strict_types=1);

namespace App\Tests\Unit\Football;

use App\Entity\ImportBatch;
use App\Entity\Player;
use App\Entity\PlayerCompetitionStats;
use App\Entity\PlayerSeason;
use App\Entity\Season;
use App\Entity\StatsScope;
use App\Football\Scoring\Component;
use App\Football\Scoring\Metric;
use App\Football\Scoring\MetricMode;
use App\Football\Scoring\ModelCatalog;
use App\Football\Scoring\ScoreCalculator;
use App\Football\Scoring\ScoringCandidate;
use App\Football\Scoring\ScoringModel;
use PHPUnit\Framework\TestCase;

final class ScoreCalculatorTest extends TestCase
{
    public function testRanksOnPercentilesAndExplainsEachComponent(): void
    {
        $results = (new ScoreCalculator())->score($this->model(), [
            $this->candidate(1, 'Low', goals: 2.0),
            $this->candidate(2, 'High', goals: 20.0),
            $this->candidate(3, 'Mid', goals: 10.0),
        ]);

        self::assertSame(['High', 'Mid', 'Low'], array_map(static fn ($r) => $r->candidate->name, $results));
        self::assertSame([1, 2, 3], array_map(static fn ($r) => $r->rank, $results));
        self::assertEqualsWithDelta(5 / 6 * 100, $results[0]->components['output']['metrics']['goals']['percentile'], 1e-9);
        self::assertSame(20.0, $results[0]->components['output']['metrics']['goals']['value']);
        self::assertSame(1.0, $results[0]->confidence);
    }

    public function testIneligiblePlayersAreLeftOut(): void
    {
        $results = (new ScoreCalculator())->score($this->model(), [
            $this->candidate(1, 'Keeper', goals: 0.0, group: 'GK'),
            $this->candidate(2, 'Few minutes', goals: 3.0, minutes: 500.0),
            $this->candidate(3, 'Eligible', goals: 1.0),
        ]);

        self::assertSame(['Eligible'], array_map(static fn ($r) => $r->candidate->name, $results));
    }

    public function testMissingComponentIsLeftOutNotZeroAndLowersConfidence(): void
    {
        $results = (new ScoreCalculator())->score($this->model(minConfidence: 0.3), [
            $this->candidate(1, 'No goals data', goals: null),
            $this->candidate(2, 'Full data', goals: 5.0),
        ]);
        $missing = array_values(array_filter($results, static fn ($r) => $r->candidate->name === 'No goals data'))[0];

        self::assertNull($missing->components['output']['score']);
        self::assertSame(0.5, $missing->confidence);
        self::assertSame($missing->components['availability']['score'], $missing->score, 'score from what is known');
    }

    public function testBelowMinimumConfidenceIsListedUnranked(): void
    {
        $results = (new ScoreCalculator())->score($this->model(minConfidence: 0.6), [
            $this->candidate(1, 'No goals data', goals: null, minutes: 3400.0),
            $this->candidate(2, 'Full data', goals: 1.0, minutes: 1000.0),
        ]);

        self::assertSame(['Full data', 'No goals data'], array_map(static fn ($r) => $r->candidate->name, $results));
        self::assertSame([1, null], array_map(static fn ($r) => $r->rank, $results));
    }

    public function testSameInputGivesSameOrder(): void
    {
        $candidates = [$this->candidate(2, 'B', goals: 5.0), $this->candidate(1, 'A', goals: 5.0)];

        $first = (new ScoreCalculator())->score($this->model(), $candidates);
        $second = (new ScoreCalculator())->score($this->model(), array_reverse($candidates));

        self::assertSame(['A', 'B'], array_map(static fn ($r) => $r->candidate->name, $first), 'tie: same minutes, lower player id first');
        self::assertSame(['A', 'B'], array_map(static fn ($r) => $r->candidate->name, $second));
    }

    public function testEqualScoresAreOrderedByMinutesThenPlayerId(): void
    {
        $goalsOnly = new ScoringModel('test', '1', 'Test', '', StatsScope::League, 900, ['FW'], 0.0, [
            new Component('output', 'Output', '', 1.0, [new Metric('goals', 'Goals', ['goals'], MetricMode::Total, 1.0)]),
        ]);

        $results = (new ScoreCalculator())->score($goalsOnly, [
            $this->candidate(3, 'Fewer minutes', goals: 5.0, minutes: 1500.0),
            $this->candidate(2, 'Id 2', goals: 5.0, minutes: 2500.0),
            $this->candidate(1, 'Id 1', goals: 5.0, minutes: 2500.0),
        ]);

        self::assertSame(['Id 1', 'Id 2', 'Fewer minutes'], array_map(static fn ($r) => $r->candidate->name, $results));
    }

    public function testConfidenceAtTheThresholdIsRankedDespiteRounding(): void
    {
        // 0.4 + 0.2 = 0.6000000000000001, 0.1 + 0.2 = 0.30000000000000004: both must count as reaching 0.6 / 0.3.
        $model = new ScoringModel('test', '1', 'Test', '', StatsScope::League, 900, ['FW'], 0.3, [
            new Component('a', 'A', '', 0.1, [new Metric('goals', 'Goals', ['goals'], MetricMode::Total, 1.0)]),
            new Component('b', 'B', '', 0.2, [new Metric('minutes', 'Minutes', ['minutes'], MetricMode::Total, 1.0)]),
            new Component('c', 'C', '', 0.7, [new Metric('xg', 'xG', ['xg'], MetricMode::Total, 1.0)]),
        ]);

        $results = (new ScoreCalculator())->score($model, [$this->candidate(1, 'A', goals: 1.0)]);

        self::assertSame(1, $results[0]->rank);
    }

    public function testBaselineModelIsValid(): void
    {
        $model = ModelCatalog::get(ModelCatalog::DEFAULT);

        self::assertSame('baseline-v1', $model->id());
        self::assertSame(64, strlen($model->hash()));
        self::assertEqualsWithDelta(1.0, array_sum(array_map(static fn (Component $c) => $c->weight, $model->components)), 1e-9);
    }

    private function model(float $minConfidence = 0.0): ScoringModel
    {
        return new ScoringModel('test', '1', 'Test', 'Test model', StatsScope::League, 900, ['FW', 'MF', 'DF'], $minConfidence, [
            new Component('output', 'Output', '', 0.5, [new Metric('goals', 'Goals', ['goals'], MetricMode::Total, 1.0)]),
            new Component('availability', 'Availability', '', 0.5, [new Metric('minutes', 'Minutes', ['minutes'], MetricMode::Total, 1.0)]),
        ]);
    }

    private function candidate(int $id, string $name, ?float $goals, string $group = 'FW', float $minutes = 2000.0): ScoringCandidate
    {
        $values = ['minutes' => $minutes] + ($goals !== null ? ['goals' => $goals] : []);
        $season = new PlayerSeason(new Player($name), new Season('2025/26', new \DateTimeImmutable(), new \DateTimeImmutable()));
        $stats = new PlayerCompetitionStats(
            $season,
            StatsScope::League,
            new ImportBatch('test', 'test', '2025/26', 'test', '1'),
            $minutes,
            $values,
            array_fill_keys(array_keys($values), 'FBref'),
            ['FBref' => ['status' => 'ok', 'minutes' => $minutes, 'reason' => null]],
        );

        return new ScoringCandidate($id, $name, $group, 'Club', $stats);
    }
}
