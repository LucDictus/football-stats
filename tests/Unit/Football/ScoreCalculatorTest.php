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
use App\Football\Scoring\Impact\ImpactModel;
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

    public function testShrinkagePullsSmallSamplesTowardTheAverage(): void
    {
        $model = new ScoringModel('test', '1', 'Test', '', StatsScope::League, 900, ['FW'], 0.0, [
            new Component('output', 'Output', '', 1.0, [new Metric('goals_p90', 'Goals per 90', ['goals'], MetricMode::Per90, 1.0, shrinkage: 10.0)]),
        ]);

        $results = (new ScoreCalculator())->score($model, [
            $this->candidate(1, 'Hot streak', goals: 10.0, minutes: 900.0),    // 1.0 per 90 over 10 matches
            $this->candidate(2, 'Season-long', goals: 30.0, minutes: 2700.0),  // 1.0 per 90 over 30 matches
            $this->candidate(3, 'Average', goals: 0.0, minutes: 2700.0),
        ]);
        $byName = array_column(array_map(static fn ($r) => ['name' => $r->candidate->name, 'r' => $r], $results), 'r', 'name');
        $adjusted = static fn (string $n) => $byName[$n]->components['output']['metrics']['goals_p90']['adjusted'] ?? null;

        // (rate × matches + average × 10) / (matches + 10), with the average of the raw rates = 2/3
        self::assertEqualsWithDelta((1.0 * 10 + (2 / 3) * 10) / 20, $adjusted('Hot streak'), 1e-9);
        self::assertEqualsWithDelta((1.0 * 30 + (2 / 3) * 10) / 40, $adjusted('Season-long'), 1e-9);
        self::assertGreaterThan($byName['Hot streak']->score, $byName['Season-long']->score, 'the same rate over more matches is more convincing');
        self::assertSame(1.0, $byName['Hot streak']->components['output']['metrics']['goals_p90']['value'], 'the raw value stays visible');
    }

    public function testShrunkRatesUseAttemptsAsTheSample(): void
    {
        $model = new ScoringModel('test', '1', 'Test', '', StatsScope::League, 900, ['DF'], 0.0, [
            new Component('duels', 'Duels', '', 1.0, [new Metric('tackle_pct', 'Tackles won %', ['tackle_success_pct'], MetricMode::Rate, 1.0, shrinkage: 20.0, sampleFields: ['tackles'])]),
        ]);

        $results = (new ScoreCalculator())->score($model, [
            $this->candidate(1, '15 of 15', goals: null, group: 'DF', extra: ['tackle_success_pct' => 100.0, 'tackles' => 15.0]),
            $this->candidate(2, '60 of 80', goals: null, group: 'DF', extra: ['tackle_success_pct' => 75.0, 'tackles' => 80.0]),
        ]);
        $adjusted = array_column(array_map(static fn ($r) => [$r->candidate->name, $r->components['duels']['metrics']['tackle_pct']['adjusted'] ?? null], $results), 1, 0);

        self::assertEqualsWithDelta((100 * 15 + 87.5 * 20) / 35, $adjusted['15 of 15'], 1e-9);
        self::assertEqualsWithDelta((75 * 80 + 87.5 * 20) / 100, $adjusted['60 of 80'], 1e-9);
    }

    public function testPossessionIsAdjustedForBeforeShrinkageAndTheMeasuredValueIsKept(): void
    {
        $model = new ScoringModel('test', '1', 'Test', '', StatsScope::League, 900, ['DF'], 0.0, [
            new Component('defending', 'Defending', '', 1.0, [new Metric('clearances_p90', 'Clearances per 90', ['clearances'], MetricMode::Per90, 1.0, shrinkage: 10.0, possessionAdjusted: true)]),
        ]);
        $candidates = array_map(fn (int $i) => $this->candidate($i, "Team {$i}", goals: null, group: 'DF', minutes: 10000.0, extra: [
            'accurate_passes' => [1 => 6000.0, 2 => 4000.0, 3 => 2500.0][$i],
            'clearances' => [1 => 200.0, 2 => 300.0, 3 => 450.0][$i],
        ], team: "Team {$i}", league: 'PL'), [1, 2, 3]);

        $results = (new ScoreCalculator())->score($model, $candidates);
        $possession = ScoreCalculator::possessionAdjustment($model, $candidates);
        self::assertNotNull($possession);

        $byName = array_column(array_map(static fn ($r) => [$r->candidate->name, $r->components['defending']['metrics']['clearances_p90']], $results), 1, 0);
        $factors = array_map(static fn ($c) => $possession->factor($c, 'clearances'), $candidates);
        $adjusted = array_map(static fn ($c, float $f) => (float) $c->stats->value('clearances') / 10000 * 90 * $f, $candidates, $factors);
        $average = array_sum($adjusted) / 3;
        $matches = 10000 / 90;
        self::assertEqualsWithDelta(200 / 10000 * 90, $byName['Team 1']['value'], 1e-9, 'the measured value is kept');
        self::assertEqualsWithDelta($factors[0], $byName['Team 1']['possession_factor'] ?? null, 1e-12);
        self::assertGreaterThan(1.0, $factors[0], 'the team with the most possession is scaled up');
        self::assertEqualsWithDelta(($adjusted[0] * $matches + $average * 10) / ($matches + 10), $byName['Team 1']['adjusted'] ?? null, 1e-9);
    }

    public function testTheReferenceGroupCanBeTheRankedPlayersOnly(): void
    {
        $model = static fn (bool $rankedOnly) => new ScoringModel('test', '1', 'Test', '', StatsScope::League, 900, ['FW'], 0.6, [
            new Component('output', 'Output', '', 0.5, [new Metric('goals', 'Goals', ['goals'], MetricMode::Total, 1.0)]),
            new Component('availability', 'Availability', '', 0.5, [new Metric('minutes', 'Minutes', ['minutes'], MetricMode::Total, 1.0)]),
        ], normalization: Normalization::Standard, referenceRankedOnly: $rankedOnly);
        $candidates = [
            $this->candidate(1, 'A', goals: 10.0, minutes: 2000.0),
            $this->candidate(2, 'B', goals: 20.0, minutes: 2000.0),
            $this->candidate(3, 'No goals data, many minutes', goals: null, minutes: 3400.0),
        ];
        $minutesScore = static fn (array $results) => array_column(array_map(static fn ($r) => [$r->candidate->name, $r->components['availability']['score']], $results), 1, 0)['A'];

        // With everyone as reference the unranked 3,400-minute player sets the average; without him A and B are exactly average.
        self::assertSame(50.0, $minutesScore((new ScoreCalculator())->score($model(true), $candidates)));
        self::assertLessThan(50.0, $minutesScore((new ScoreCalculator())->score($model(false), $candidates)));
    }

    public function testSquareRootScaleTamesASkewedMetric(): void
    {
        $metric = static fn (bool $sqrt) => new ScoringModel('test', '1', 'Test', '', StatsScope::League, 900, ['DF'], 0.0, [
            new Component('attack', 'Attack', '', 1.0, [new Metric('goals_p90', 'Goals per 90', ['goals'], MetricMode::Per90, 1.0, sqrt: $sqrt)]),
        ], normalization: Normalization::Standard);
        // Twenty defenders who almost never score, one attacking full-back who often does.
        $candidates = [...array_map(fn (int $i) => $this->candidate($i, "Defender {$i}", goals: (float) ($i % 2), group: 'DF'), range(1, 20)),
            $this->candidate(21, 'Full-back', goals: 12.0, group: 'DF')];
        $fullBack = static fn (array $results) => array_values(array_filter($results, static fn ($r) => $r->candidate->name === 'Full-back'))[0]->score;

        $raw = $fullBack((new ScoreCalculator())->score($metric(false), $candidates));
        $sqrt = $fullBack((new ScoreCalculator())->score($metric(true), $candidates));

        self::assertLessThan($raw, $sqrt, 'less extreme on a square-root scale');
        self::assertGreaterThan(80.0, $sqrt, 'but still far above his peers');
    }

    public function testZCapLimitsHowFarOneMetricCanCarryAPlayer(): void
    {
        $values = [...array_fill(0, 20, 1.0), ...array_fill(0, 20, 2.0), 50.0];

        $capped = Normalization::Standard->scores($values, zCap: 3.0);
        $free = Normalization::Standard->scores($values);

        self::assertSame(80.0, $capped[40]);
        self::assertGreaterThan(80.0, $free[40]);
    }

    public function testBlendedModelsCombinePositionalScoreAndContribution(): void
    {
        $model = new ScoringModel('test', '1', 'Test', '', StatsScope::League, 900, ['FW'], 0.6, [], [
            'FW' => [new Component('output', 'Output', '', 1.0, [new Metric('goals', 'Goals', ['goals'], MetricMode::Total, 1.0)])],
        ], Normalization::Percentile, impact: new ImpactModel(), impactWeight: 0.5);

        $results = (new ScoreCalculator())->score($model, [
            $this->candidate(1, 'A', goals: 10.0, extra: ['np_xg' => 4.0, 'xa' => 1.0, 'xg_buildup' => 1.0], understat: 'ok'),
            $this->candidate(2, 'B', goals: 20.0, extra: ['np_xg' => 12.0, 'xa' => 2.0, 'xg_buildup' => 1.0], understat: 'ok'),
            $this->candidate(3, 'No attacking data', goals: 30.0),
        ]);
        $byName = array_column(array_map(static fn ($r) => ['name' => $r->candidate->name, 'r' => $r], $results), 'r', 'name');

        foreach (['A', 'B'] as $name) {
            $r = $byName[$name];
            self::assertNotNull($r->impact);
            self::assertEqualsWithDelta(0.5 * (float) $r->positional + 0.5 * (float) $r->impact['score'], $r->score, 1e-9);
        }
        self::assertSame(1, $byName['B']->rank);
        self::assertNull($byName['No attacking data']->rank, 'half the model unmeasured: not ranked');
        self::assertSame(0.5, $byName['No attacking data']->confidence);
    }

    public function testAKnownContributionDoesNotLiftThinPositionalData(): void
    {
        $model = new ScoringModel('test', '1', 'Test', '', StatsScope::League, 900, ['FW'], 0.6, [], [
            'FW' => [
                new Component('output', 'Output', '', 0.5, [new Metric('goals', 'Goals', ['goals'], MetricMode::Total, 1.0)]),
                new Component('duels', 'Duels', '', 0.5, [new Metric('aerial', 'Aerial %', ['aerial_win_pct'], MetricMode::Rate, 1.0)]),
            ],
        ], Normalization::Percentile, impact: new ImpactModel(), impactWeight: 0.5);
        $attack = ['np_xg' => 5.0, 'xa' => 1.0, 'xg_buildup' => 1.0];

        $results = (new ScoreCalculator())->score($model, [
            $this->candidate(1, 'Complete', goals: 10.0, extra: $attack + ['aerial_win_pct' => 50.0], understat: 'ok'),
            // Half the positional model unmeasured (no duel data): 0.5 < 0.6, even though 0.5 × 0.5 + 0.5 × 1 = 0.75.
            $this->candidate(2, 'Thin positional data', goals: 12.0, extra: $attack, understat: 'ok'),
        ]);
        $byName = array_column(array_map(static fn ($r) => ['name' => $r->candidate->name, 'r' => $r], $results), 'r', 'name');

        self::assertSame(1, $byName['Complete']->rank);
        self::assertNull($byName['Thin positional data']->rank);
    }

    public function testReleasedModelsAreUnchanged(): void
    {
        // A released model must never change: their hashes are pinned (and match their stored runs).
        self::assertSame('74768eddf47b9da716df9fbe8c9740cab248f411c56323d019184e795a2739b8', ModelCatalog::get('baseline-v1')->hash());
        self::assertSame('e042f01b224f4fd0648ad9a9188ef4b08afbd777ce981d5126ee95a90ebfe1e0', ModelCatalog::get('positional-v1')->hash());
        self::assertSame('2da8697cbf0f4bb933f51fb8eba2f5c9dd5ce9c0a971e655f6c7a04012eb5a3f', ModelCatalog::get('positional-v2')->hash());
        self::assertSame('dc550a3f0239848e236e42cc9754c4caa314f16f0f8dc1cdcd26c79853d097c2', ModelCatalog::get('mvp-v1')->hash());
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

    public function testOnlySingleFieldPer90MetricsCanBeAdjustedForPossession(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Metric('ga', 'G+A', ['np_goals', 'assists'], MetricMode::Per90, 1.0, possessionAdjusted: true);
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
    private function candidate(int $id, string $name, ?float $goals, string $group = 'FW', float $minutes = 2000.0, array $extra = [], string $sofascore = 'ok', ?string $understat = null, string $team = 'Club', ?string $league = null): ScoringCandidate
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
            ] + ($understat !== null ? ['Understat' => ['status' => $understat, 'minutes' => $minutes, 'reason' => null]] : []),
        );

        return new ScoringCandidate($id, $name, $group, $team, $stats, $league);
    }
}
