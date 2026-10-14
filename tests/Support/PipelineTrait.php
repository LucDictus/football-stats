<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\DataImport\Normalizer\SeasonBuilder;
use App\DataImport\RawImporter;
use App\DataImport\Sofascore\SofascoreClient;
use App\DataImport\Top5\Top5CsvReader;
use App\Entity\ImportBatch;
use App\Entity\Player;
use App\Entity\PlayerCompetitionStats;
use App\Entity\PlayerSeason;
use App\Entity\StatsScope;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Runs the real import → build pipeline on the test database. For KernelTestCase and
 * WebTestCase tests; call resetDatabase() in setUp().
 */
trait PipelineTrait
{
    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function resetDatabase(): void
    {
        $connection = $this->em()->getConnection();
        $tables = $connection->fetchFirstColumn(
            "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename <> 'doctrine_migration_versions'"
        );
        $connection->executeStatement('TRUNCATE ' . implode(', ', $tables) . ' RESTART IDENTITY CASCADE');
    }

    /** @param list<array<string, string>> $rows */
    private function importTop5(array $rows): void
    {
        $payloads = [];
        foreach ($rows as $row) {
            $payloads[implode('|', [$row['player'], $row['born'], $row['team'], $row['league']])] = $row;
        }
        self::getContainer()->get(RawImporter::class)->import(new ImportBatch(Top5CsvReader::SOURCE, Top5CsvReader::DATASET, '2025/26', 'test', '1'), $payloads);
    }

    /** @param list<array<string, mixed>> $records */
    private function importCl(array $records): void
    {
        $payloads = [];
        foreach ($records as $record) {
            $payloads[$record['player']['id'] . '|' . $record['team']['id']] = $record;
        }
        self::getContainer()->get(RawImporter::class)->import(new ImportBatch(SofascoreClient::SOURCE, SofascoreClient::DATASET, '2025/26', 'test', '1', 'CL'), $payloads);
    }

    /** @return array<string, mixed> */
    private function build(): array
    {
        $result = self::getContainer()->get(SeasonBuilder::class)->build();
        $this->em()->clear();

        return $result;
    }

    private function seasonOf(string $name): PlayerSeason
    {
        $player = $this->em()->getRepository(Player::class)->findOneBy(['name' => $name]);
        $season = $this->em()->getRepository(PlayerSeason::class)->findOneBy(['player' => $player]);
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
