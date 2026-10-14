<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\PlayerScore;
use App\Football\Scoring\ModelCatalog;
use App\Football\Scoring\ScoreRunner;
use App\Tests\Support\PipelineTrait;
use App\Tests\Support\SourceFixtures as F;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ScoreRunnerTest extends KernelTestCase
{
    use PipelineTrait;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->resetDatabase();
        $this->importTop5([
            F::top5Row(['player' => 'Striker', 'fb_gls' => '20', 'ss_goals' => '20', 'ss_penaltyGoals' => '2', 'us_np_xg' => '15', 'us_xa' => '3']),
            F::top5Row(['player' => 'Winger', 'fb_gls' => '8', 'ss_goals' => '8', 'ss_penaltyGoals' => '0', 'us_np_xg' => '6', 'us_xa' => '7']),
            F::top5Row(['player' => 'Keeper', 'pos' => 'GK']),
            F::top5Row(['player' => 'Sub', 'playing_time_min' => '400', 'us_minutes' => '400', 'ss_minutesPlayed' => '400']),
        ]);
        $this->importCl([F::sofascoreRecord(1, 'Someone', 1, 'Club')]);
        $this->build();
    }

    public function testStoresARankingWithBreakdownAndIsReproducible(): void
    {
        $runner = self::getContainer()->get(ScoreRunner::class);
        $model = ModelCatalog::get('baseline-v1');

        $first = $runner->run($model);
        $second = $runner->run($model);

        self::assertTrue($first['created']);
        self::assertFalse($second['created'], 'same model + data = same run');
        self::assertSame($first['run']->getId(), $second['run']->getId());
        self::assertSame(2, $first['run']->getRankedCount(), 'keeper and 400-minute sub are not eligible');
        self::assertSame($model->hash(), $first['run']->getDefinitionHash());

        $top = $this->em()->getRepository(PlayerScore::class)->findOneBy(['run' => $first['run'], 'rank' => 1]);
        self::assertNotNull($top);
        self::assertSame('Striker', $top->getPlayer()->getName());
        self::assertSame(19.0, $top->getComponents()['attacking_output']['metrics']['np_ga']['value'], '20 goals - 2 penalties + 1 assist');
    }

    public function testAChangedBreakdownGivesANewRunEvenWithTheSameRanks(): void
    {
        $runner = self::getContainer()->get(ScoreRunner::class);
        $first = $runner->run(ModelCatalog::get('baseline-v1'));
        // Simulate a code change that alters confidence but no rank or score.
        $this->em()->getConnection()->executeStatement('UPDATE player_score SET confidence = 0.5 WHERE run_id = ?', [$first['run']->getId()]);
        $this->em()->clear(); // as in a new process: read what is stored

        self::assertTrue($runner->run(ModelCatalog::get('baseline-v1'))['created']);
    }

    public function testNewDataGivesANewRun(): void
    {
        $runner = self::getContainer()->get(ScoreRunner::class);
        $first = $runner->run(ModelCatalog::get('baseline-v1'));

        $this->importTop5([
            F::top5Row(['player' => 'Striker', 'fb_gls' => '25', 'ss_goals' => '25', 'ss_penaltyGoals' => '2']),
            F::top5Row(['player' => 'Winger', 'fb_gls' => '8', 'ss_goals' => '8', 'ss_penaltyGoals' => '0']),
        ]);
        $this->build();
        $second = $runner->run(ModelCatalog::get('baseline-v1'));

        self::assertTrue($second['created']);
        self::assertNotSame($first['run']->getId(), $second['run']->getId());
    }
}
