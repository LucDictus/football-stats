<?php

declare(strict_types=1);

namespace App\Tests\Unit\Football;

use App\Entity\ImportBatch;
use App\Entity\Player;
use App\Entity\PlayerCompetitionStats;
use App\Entity\PlayerSeason;
use App\Entity\Season;
use App\Entity\StatsScope;
use App\Football\Analysis\OverlapAnalysis;
use App\Football\Scoring\ModelCatalog;
use App\Football\Scoring\PlayerScoreResult;
use App\Football\Scoring\ScoringCandidate;
use PHPUnit\Framework\TestCase;

final class OverlapAnalysisTest extends TestCase
{
    /** Positional half: scoring 60%, defending 40%; contribution: shooting goals only. */
    private const ROWS = [
        // [scoring, defending, shooting goals]
        [80.0, 40.0, 9.0],
        [60.0, 70.0, 6.0],
        [50.0, 50.0, 5.0],
        [40.0, 65.0, 3.5],
        [30.0, 30.0, 3.0],
        [45.0, 55.0, 4.0],
    ];

    public function testSharesExplainTheWholeFinalScore(): void
    {
        $analysis = (new OverlapAnalysis())->analyse(ModelCatalog::get('mvp-v1'), $this->results(self::ROWS));
        $fw = $analysis['roles']['FW'];

        self::assertEqualsWithDelta(0.0, $fw['residual'], 1e-9);
        self::assertEqualsWithDelta(1.0, array_sum($fw['shares']), 1e-9);
        // attacking output (positional) and shooting goals (contribution) are one family
        self::assertEqualsWithDelta($fw['shares']['attacking_output'] + $fw['shares']['impact.shooting'], $fw['families']['scoring'], 1e-9);
    }

    public function testTheSameInformationInBothHalvesIsFlagged(): void
    {
        $analysis = (new OverlapAnalysis())->analyse(ModelCatalog::get('mvp-v1'), $this->results(self::ROWS));
        $pairs = array_map(static fn (array $p) => $p['a'] . '|' . $p['b'], $analysis['roles']['FW']['overlaps']);

        self::assertContains('attacking_output|impact.shooting', $pairs);
        self::assertGreaterThan(0.7, $analysis['roles']['FW']['positional_impact_correlation'], 'the positional half also holds defending');
    }

    public function testTheCounterfactualKeepsOnlyWhatThePositionalHalfAdds(): void
    {
        $analysis = (new OverlapAnalysis())->analyse(ModelCatalog::get('mvp-v1'), $this->results(self::ROWS));

        self::assertNotNull($analysis['counterfactual']);
        self::assertCount(6, $analysis['counterfactual']['leaders']);
        self::assertSame(6, $analysis['counterfactual']['top'][10]['FW']['counterfactual']);
        self::assertNull((new OverlapAnalysis())->analyse(ModelCatalog::get('positional-v2'), $this->results(self::ROWS))['counterfactual']);
    }

    /**
     * Results whose final score is exactly 50% positional (re-standardised composite) + 50%
     * contribution score (linear in goals above a replacement level proportional to minutes).
     *
     * @param list<array{float, float, float}> $rows
     *
     * @return list<PlayerScoreResult>
     */
    private function results(array $rows): array
    {
        $results = [];
        foreach ($rows as $i => [$scoring, $defending, $shooting]) {
            $composite = 0.6 * $scoring + 0.4 * $defending;
            $positional = 2 * $composite - 50;
            $impactScore = 30 + 5 * ($shooting - 1.0);
            $results[] = new PlayerScoreResult(
                $this->candidate($i + 1),
                $i + 1,
                0.5 * $positional + 0.5 * $impactScore,
                1.0,
                [
                    'attacking_output' => ['score' => $scoring, 'weight' => 0.6, 'coverage' => 1.0, 'metrics' => ['np_ga_p90' => ['value' => null, 'percentile' => $scoring, 'weight' => 1.0]]],
                    'defending' => ['score' => $defending, 'weight' => 0.4, 'coverage' => 1.0, 'metrics' => ['tackles_won_p90' => ['value' => null, 'percentile' => $defending, 'weight' => 1.0]]],
                ],
                $composite,
                $positional,
                ['goals' => ['shooting' => $shooting], 'total' => $shooting, 'replacement' => 1.0, 'above' => $shooting - 1.0, 'attack_source' => 'Understat', 'score' => $impactScore, 'weight' => 0.5],
            );
        }

        return $results;
    }

    private function candidate(int $id): ScoringCandidate
    {
        $season = new PlayerSeason(new Player("P{$id}"), new Season('2025/26', new \DateTimeImmutable(), new \DateTimeImmutable()));
        $stats = new PlayerCompetitionStats($season, StatsScope::League, new ImportBatch('test', 'test', '2025/26', 'test', '1'), 2000.0 + 100 * $id, [], [], []);

        return new ScoringCandidate($id, "P{$id}", 'FW', 'Club', $stats, 'PL');
    }
}
