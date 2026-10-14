<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\DataImport\Normalizer\SeasonBuilder;
use App\DataImport\RawImporter;
use App\DataImport\Sofascore\SofascoreClient;
use App\DataImport\Top5\Top5CsvReader;
use App\Entity\ImportBatch;
use App\Entity\Player;
use App\Entity\PlayerCompetitionStats;
use App\Entity\PlayerSeason;
use App\Entity\StatsScope;
use App\Football\Data\Origin;
use App\Football\Data\OriginStatus;
use App\Tests\Support\SourceFixtures as F;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Runs the real pipeline against the PostgreSQL test database. */
final class SeasonBuilderTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $tables = $this->em->getConnection()->fetchFirstColumn(
            "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename <> 'doctrine_migration_versions'"
        );
        $this->em->getConnection()->executeStatement('TRUNCATE ' . implode(', ', $tables) . ' RESTART IDENTITY CASCADE');
    }

    public function testRebuildingCreatesNoDuplicatesAndKeepsIds(): void
    {
        $this->importDefaultSources();
        $first = $this->build();
        $ids = $this->playerIds();
        $second = $this->build();

        self::assertSame(3, $first['players_created']);
        self::assertSame(0, $second['players_created']);
        self::assertSame(0, $second['teams_created']);
        self::assertSame(0, $second['identifiers_added']);
        self::assertSame($ids, $this->playerIds());
        self::assertSame(3, $this->em->getRepository(PlayerSeason::class)->count([]));
    }

    public function testLeagueAndClStatsBelongToOnePlayerSeason(): void
    {
        $this->importDefaultSources();
        $this->build();

        $season = $this->seasonOf('Kylian Mbappé');
        $league = $this->statsOf('Kylian Mbappé', StatsScope::League);
        $cl = $this->statsOf('Kylian Mbappé', StatsScope::ChampionsLeague);

        self::assertSame(25.0, $league->value('goals'));
        self::assertSame(Origin::Fbref, $league->origin('goals'));
        self::assertSame(15.0, $cl->value('goals'));
        self::assertSame(Origin::Sofascore, $cl->origin('goals'));
        self::assertSame('Real Madrid', $season->getMainTeam()?->getName());
        self::assertSame(['PD'], $season->getStints()->map(static fn ($s) => $s->getCompetition()->getCode())->getValues());
        self::assertSame('top5-football-dataset', $league->getBatch()->getSource());
    }

    public function testClOnlyPlayerGetsASeasonWithClStatsOnly(): void
    {
        $this->importDefaultSources();
        $this->build();

        $season = $this->seasonOf('Victor Osimhen');
        $team = $season->getMainTeam();

        self::assertNull($season->getStats(StatsScope::League));
        self::assertSame(7.0, $this->statsOf('Victor Osimhen', StatsScope::ChampionsLeague)->value('goals'));
        self::assertNotNull($team);
        self::assertSame('Galatasaray', $team->getName());
        self::assertNull($team->getDomesticLeague());
    }

    public function testNewDatasetVersionUpdatesStatsButNotIdentity(): void
    {
        $this->importDefaultSources();
        $this->build();
        $id = $this->seasonOf('Kylian Mbappé')->getPlayer()->getId();

        // The source corrects a number: a new batch, same player.
        $this->importTop5([F::top5Row(['player' => 'Kylian Mbappé', 'born' => '1998', 'team' => 'Real Madrid', 'league' => 'ESP-La Liga', 'fb_gls' => '26', 'ss_player_id' => '826643.0'])]);
        $this->build();

        $stats = $this->statsOf('Kylian Mbappé', StatsScope::League);
        self::assertSame($id, $stats->getPlayerSeason()->getPlayer()->getId());
        self::assertSame(26.0, $stats->value('goals'));
        self::assertSame(3, $stats->getBatch()->getId(), 'batches 1 and 2 are the default sources');
    }

    public function testTransferBetweenLeaguesKeepsProviderMinutesForPer90(): void
    {
        $this->importTop5([
            F::top5Row(['player' => 'Piero Hincapié', 'born' => '2002', 'team' => 'Arsenal', 'playing_time_min' => '1792', 'playing_time_mp' => '20', 'ss_minutesPlayed' => '90', 'ss_tackles' => '3']),
            F::top5Row(['player' => 'Piero Hincapié', 'born' => '2002', 'team' => 'Leverkusen', 'league' => 'GER-Bundesliga', 'playing_time_min' => '90', 'playing_time_mp' => '1', 'ss_minutesPlayed' => '90', 'ss_tackles' => '3']),
        ]);
        $this->importCl([]);
        $this->build();

        $stats = $this->statsOf('Piero Hincapié', StatsScope::League);
        self::assertSame(1882.0, $stats->getMinutes());
        self::assertSame(OriginStatus::Partial, $stats->originStatus(Origin::Sofascore));
        self::assertEqualsWithDelta(3.0, $stats->per90('tackles'), 0.001, '3 tackles in 90 SofaScore minutes, not in 1882');
        self::assertFalse($stats->trusted('tackles'));
        self::assertCount(2, $this->seasonOf('Piero Hincapié')->getStints());
    }

    private function importDefaultSources(): void
    {
        $this->importTop5([
            F::top5Row(['player' => 'Kylian Mbappé', 'born' => '1998', 'team' => 'Real Madrid', 'league' => 'ESP-La Liga', 'fb_gls' => '25', 'ss_goals' => '25', 'ss_player_id' => '826643.0']),
            F::top5Row(['player' => 'Fabián Ruiz Peña', 'born' => '1996', 'team' => 'PSG', 'league' => 'FRA-Ligue 1']),
        ]);
        $this->importCl([
            F::sofascoreRecord(826643, 'Kylian Mbappé', 2829, 'Real Madrid', ['goals' => 15]),
            F::sofascoreRecord(1, 'Victor Osimhen', 3061, 'Galatasaray', ['goals' => 7]),
        ]);
    }

    /** @param list<array<string, string>> $rows */
    private function importTop5(array $rows): void
    {
        $payloads = [];
        foreach ($rows as $row) {
            $payloads[implode('|', [$row['player'], $row['born'], $row['team'], $row['league']])] = $row;
        }
        $this->rawImporter()->import(new ImportBatch(Top5CsvReader::SOURCE, Top5CsvReader::DATASET, '2025/26', 'test', '1'), $payloads);
    }

    /** @param list<array<string, mixed>> $records */
    private function importCl(array $records): void
    {
        $payloads = [];
        foreach ($records as $record) {
            $payloads[$record['player']['id'] . '|' . $record['team']['id']] = $record;
        }
        $this->rawImporter()->import(new ImportBatch(SofascoreClient::SOURCE, SofascoreClient::DATASET, '2025/26', 'test', '1', 'CL'), $payloads);
    }

    private function rawImporter(): RawImporter
    {
        return self::getContainer()->get(RawImporter::class);
    }

    /** @return array<string, mixed> */
    private function build(): array
    {
        $result = self::getContainer()->get(SeasonBuilder::class)->build();
        $this->em->clear();

        return $result;
    }

    /** @return array<string, int> name => id */
    private function playerIds(): array
    {
        $ids = [];
        foreach ($this->em->getRepository(Player::class)->findAll() as $player) {
            $ids[$player->getName()] = (int) $player->getId();
        }
        ksort($ids);

        return $ids;
    }

    private function seasonOf(string $name): PlayerSeason
    {
        $player = $this->em->getRepository(Player::class)->findOneBy(['name' => $name]);
        $season = $this->em->getRepository(PlayerSeason::class)->findOneBy(['player' => $player]);
        self::assertNotNull($season, "{$name} has no season");

        return $season;
    }

    private function statsOf(string $name, StatsScope $scope): PlayerCompetitionStats
    {
        $stats = $this->seasonOf($name)->getStats($scope);
        self::assertNotNull($stats, "{$name} has no {$scope->value} stats");

        return $stats;
    }
}
