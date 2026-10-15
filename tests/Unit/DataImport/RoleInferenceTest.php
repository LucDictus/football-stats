<?php

declare(strict_types=1);

namespace App\Tests\Unit\DataImport;

use App\DataImport\Normalizer\RoleInference;
use App\Entity\ImportBatch;
use App\Entity\Player;
use App\Entity\PlayerCompetitionStats;
use App\Entity\PlayerSeason;
use App\Entity\Season;
use App\Entity\StatsScope;
use App\Football\Data\FieldCatalog;
use PHPUnit\Framework\TestCase;

final class RoleInferenceTest extends TestCase
{
    public function testTheNearestCompatibleProfileDecides(): void
    {
        $inference = new RoleInference($this->labelled());

        self::assertSame('CB', $inference->infer('DF', $this->stats(clearances: 6.0, dribbles: 0.2)));
        self::assertSame('W', $inference->infer('MF', $this->stats(clearances: 0.3, dribbles: 3.0)));
        // A defender who dribbles like a winger is still only one of the defensive roles.
        self::assertSame('CB', $inference->infer('DF', $this->stats(clearances: 0.3, dribbles: 3.0)));
    }

    public function testWithoutStatisticsTheGroupsMostCommonRoleIsUsed(): void
    {
        $inference = new RoleInference($this->labelled());

        self::assertSame('CM', $inference->infer('MF', null));
        self::assertSame('ST', $inference->infer('FW', $this->stats(clearances: 1.0, dribbles: 1.0, minutes: 300.0)), 'too few minutes for per-90 rates');
        self::assertNull($inference->infer(null, null));
    }

    public function testAccuracyLeavesEachPlayerOutOfTheTypicalProfiles(): void
    {
        $labelled = $this->labelled();
        $inference = new RoleInference($labelled);

        $players = array_map(static fn (array $p) => $p + ['group' => $p['role'] === 'CB' ? 'DF' : 'MF'], $labelled);
        // Distinct profiles: every player is recognised even without himself in his role's average.
        self::assertSame(['evaluated' => 6, 'correct' => 6], $inference->accuracy($players, 900.0));
    }

    /** @return list<array{role: string, stats: PlayerCompetitionStats}> */
    private function labelled(): array
    {
        return [
            ['role' => 'CB', 'stats' => $this->stats(clearances: 5.0, dribbles: 0.2)],
            ['role' => 'CB', 'stats' => $this->stats(clearances: 6.0, dribbles: 0.3)],
            ['role' => 'CB', 'stats' => $this->stats(clearances: 7.0, dribbles: 0.1)],
            ['role' => 'W', 'stats' => $this->stats(clearances: 0.2, dribbles: 2.5)],
            ['role' => 'W', 'stats' => $this->stats(clearances: 0.4, dribbles: 3.0)],
            ['role' => 'W', 'stats' => $this->stats(clearances: 0.3, dribbles: 3.5)],
        ];
    }

    private function stats(float $clearances, float $dribbles, float $minutes = 1800.0): PlayerCompetitionStats
    {
        // Every other feature at 1 per 90; the two that differ tell the roles apart.
        $values = array_fill_keys(RoleInference::FEATURES, $minutes / 90);
        $values['clearances'] = $clearances * $minutes / 90;
        $values['successful_dribbles'] = $dribbles * $minutes / 90;
        $season = new PlayerSeason(new Player('Test'), new Season('2025/26', new \DateTimeImmutable(), new \DateTimeImmutable()));

        return new PlayerCompetitionStats(
            $season,
            StatsScope::League,
            new ImportBatch('test', 'test', '2025/26', 'test', '1'),
            $minutes,
            $values,
            array_map(static fn (string $f) => FieldCatalog::get($f)->origin->value, array_combine(array_keys($values), array_keys($values))),
            [
                'FBref' => ['status' => 'ok', 'minutes' => $minutes, 'reason' => null],
                'SofaScore' => ['status' => 'ok', 'minutes' => $minutes, 'reason' => null],
            ],
        );
    }
}
