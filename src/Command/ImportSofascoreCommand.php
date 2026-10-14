<?php

declare(strict_types=1);

namespace App\Command;

use App\DataImport\RawImporter;
use App\DataImport\Sofascore\SofascoreClient;
use App\Entity\ImportBatch;
use App\Football\Assessment\AssessmentScope;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand('app:import:sofascore-cl', 'Import Champions League player season stats from SofaScore as raw records')]
final class ImportSofascoreCommand extends Command
{
    private const TRANSFORM_VERSION = '1';

    public function __construct(
        private readonly SofascoreClient $client,
        private readonly RawImporter $importer,
        private readonly AssessmentScope $scope,
        #[Autowire('%kernel.project_dir%/var/sources/sofascore')]
        private readonly string $snapshotRoot,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('no-fetch', null, InputOption::VALUE_NONE, 'Import the existing snapshot instead of downloading a new one');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        ['tournament' => $tournament, 'season' => $season] = $this->scope->sofascoreCl;

        $snapshot = sprintf('%s/ut%d-s%d', $this->snapshotRoot, $tournament, $season);

        if (!$input->getOption('no-fetch')) {
            $io->text('Downloading SofaScore statistics pages...');
            $io->text(sprintf('%d pages saved in %s', $this->client->fetchSnapshot($tournament, $season, $snapshot), $snapshot));
        }

        // Stored as delivered: the season build decides what to do with incomplete records.
        $payloads = [];
        foreach ($this->client->readSnapshot($snapshot) as $result) {
            $payloads[$result['player']['id'] . '|' . $result['team']['id']] = $result;
        }

        $batch = $this->importer->import(
            new ImportBatch(
                SofascoreClient::SOURCE,
                SofascoreClient::DATASET,
                $this->scope->season,
                $this->client->statisticsUrl($tournament, $season),
                self::TRANSFORM_VERSION,
                competitionCode: 'CL',
            ),
            $payloads,
            notes: ['fields' => SofascoreClient::FIELDS, 'snapshot' => basename($snapshot)],
        );

        $io->success(sprintf('Batch #%d: %d Champions League players.', $batch->getId(), $batch->getRecordCount()));

        return Command::SUCCESS;
    }
}
