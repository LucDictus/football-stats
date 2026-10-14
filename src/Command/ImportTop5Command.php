<?php

declare(strict_types=1);

namespace App\Command;

use App\DataImport\RawImporter;
use App\DataImport\Top5\Top5CsvReader;
use App\Entity\ImportBatch;
use App\Football\Assessment\AssessmentScope;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:import:top5', 'Import the Top-5 Football Dataset (league player stats) as raw records')]
final class ImportTop5Command extends Command
{
    private const TRANSFORM_VERSION = '1';

    public function __construct(
        private readonly Top5CsvReader $reader,
        private readonly RawImporter $importer,
        private readonly AssessmentScope $scope,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $result = $this->reader->read($this->scope->top5SeasonCode);
        $version = $this->reader->version();

        $batch = $this->importer->import(
            new ImportBatch(
                Top5CsvReader::SOURCE,
                Top5CsvReader::DATASET,
                $this->scope->season,
                Top5CsvReader::URL,
                self::TRANSFORM_VERSION,
                competitionCode: implode(',', $this->scope->leagues),
                sourceVersion: $version,
            ),
            $result['rows'],
            count($result['rejected']),
            ['file' => 'data/master/player_seasons.csv', 'rejected' => $result['rejected']],
        );

        $io->success(sprintf(
            'Batch #%d: %d stint rows for %s (dataset commit %s), %d rows rejected.',
            $batch->getId(),
            $batch->getRecordCount(),
            $this->scope->season,
            $version !== null ? substr($version, 0, 10) : 'unknown',
            $batch->getRejectedCount(),
        ));

        return Command::SUCCESS;
    }
}
