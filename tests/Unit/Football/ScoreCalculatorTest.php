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
use App\Football\Scoring\Normalization;
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

    public function testRoleModelsCompareEachPositionWithItsOwnGroupInOneRanking(): void
    {
        $model = new ScoringModel('test', '1', 'Test', '', StatsScope::League, 900, ['FW', 'GK'], 0.0, [], [
            'FW' => [new Component('output', 'Output', '', 1.0, [new Metric('goals', 'Goals', ['goals'], MetricMode::Total, 1.0)])],
            'GK' => [new Component('stopping', 'Shot stopping', '', 1.0, [new Metric('prevented', 'Goals prevented', ['goals_prevented'], MetricMode::Total, 1.0)])],
        ]);

        $results = (new ScoreCalculator())->score($model, [
            $this->candidate(1, 'Average striker', goals: 10.0),
            $this->candidate(2, 'Best striker', goals: 30.0),
            $this->candidate(3, 'Average keeper', goals: null, group: 'GK', extra: ['goals_prevented' => 0.0]),
            $this->candidate(4, 'Best keeper', goals: null, group: 'GK', extra: ['goals_prevented' => 9.0]),
        ]);
        $byName = array_column(array_map(static fn ($r) => ['name' => $r->candidate->name, 'r' => $r], $results), 'r', 'name');

        // Goals and goals prevented never meet: each is compared within its role only.
        self::assertSame($byName['Best striker']->score, $byName['Best keeper']->score, 'the best of each role score the same here');
        self::assertArrayHasKey('stopping', $byName['Best keeper']->components);
        self::assertArrayNotHasKey('output', $byName['Best keeper']->components);
        self::assertSame(1, $byName['Best striker']->rank, 'tie broken by composite, then minutes, then id');
    }

    public function testRoleScoreIsTheCompositeNormalisedWithinTheRole(): void
    {
        $model = new ScoringModel('test', '1', 'Test', '', StatsScope::League, 900, ['FW'], 0.0, [], [
            'FW' => [new Component('output', 'Output', '', 1.0, [new Metric('goals', 'Goals', ['goals'], MetricMode::Total, 1.0)])],
        ], Normalization::Percentile);

        $results = (new ScoreCalculator())->score($model, [$this->candidate(1, 'A', goals: 1.0), $this->candidate(2, 'B', goals: 2.0)]);

        self::assertSame(75.0, $results[0]->composite, 'percentile of 2 goals among 2 players');
        self::assertSame(75.0, $results[0]->score, 'percentile of the composite among the role');
    }

    public function testRateMetricsUseUsableValuesWithoutMinutes(): void
    {
        $model = new ScoringModel('test', '1', 'Test', '', StatsScope::League, 900, ['DF'], 0.0, [
            new Component('duels', 'Duels', '', 1.0, [new Metric('aerial', 'Aerial %', ['aerial_win_pct'], MetricMode::Rate, 1.0)]),
        ]);

        $results = (new ScoreCalculator())->score($model, [
            $this->candidate(1, 'A', goals: null, group: 'DF', extra: ['aerial_win_pct' => 70.0], sofascore: 'partial'),
            $this->candidate(2, 'B', goals: null, group: 'DF', extra: ['aerial_win_pct' => 50.0], sofascore: 'suspect'),
        ]);
        $byName = array_column(array_map(static fn ($r) => ['name' => $r->candidate->name, 'r' => $r], $results), 'r', 'name');

        self::assertSame(70.0, $byName['A']->components['duels']['metrics']['aerial']['value'], 'a partial provider still gives a fair rate');
        self::assertNull($byName['B']->components['duels']['metrics']['aerial']['value'], 'a suspect provider is not used');
    }

    public function testBaselineModelIsUnchanged(): void
    {
        // A released model must never change: its hash is pinned.
        self::assertSame('74768eddf47b9da716df9fbe8c9740cab248f411c56323d019184e795a2739b8', ModelCatalog::get('baseline-v1')->hash());
    }

    public function testEveryReleasedModelHasValidWeights(): void
    {
        foreach (ModelCatalog::all() as $model) {
            foreach ($model->positionGroups as $group) {
                $components = $model->componentsFor($group);
                self::assertEqualsWithDelta(1.0, array_sum(array_map(static fn (Component $c) => $c->weight, $components)), 1e-9, "{$model->id()} {$group}");
                foreach ($components as $component) {
                    self::assertEqualsWithDelta(1.0, array_sum(array_map(static fn (Metric $m) => $m->weight, $component->metrics)), 1e-9, "{$model->id()} {$group} {$component->key}");
                    foreach ($component->metrics as $metric) {
                        foreach ($metric->fields as $field) {
                            \App\Football\Data\FieldCatalog::get($field); // throws for an unknown field
                        }
                    }
                }
            }
        }
    }

    public function testBaselineModelIsValid(): void
    {
        $model = ModelCatalog::get('baseline-v1');

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

    /** @param array<string, float> $extra */
    private function candidate(int $id, string $name, ?float $goals, string $group = 'FW', float $minutes = 2000.0, array $extra = [], string $sofascore = 'ok'): ScoringCandidate
    {
        $values = ['minutes' => $minutes] + ($goals !== null ? ['goals' => $goals] : []) + $extra;
        $season = new PlayerSeason(new Player($name), new Season('2025/26', new \DateTimeImmutable(), new \DateTimeImmutable()));
        $stats = new PlayerCompetitionStats(
            $season,
            StatsScope::League,
            new ImportBatch('test', 'test', '2025/26', 'test', '1'),
            $minutes,
            $values,
            array_map(static fn (string $field) => \App\Football\Data\FieldCatalog::get($field)->origin->value, array_combine(array_keys($values), array_keys($values))),
            [
                'FBref' => ['status' => 'ok', 'minutes' => $minutes, 'reason' => null],
                'SofaScore' => ['status' => $sofascore, 'minutes' => $minutes, 'reason' => null],
            ],
        );

        return new ScoringCandidate($id, $name, $group, 'Club', $stats);
    }
}
