<?php

declare(strict_types=1);

namespace App\Command;

use App\DataImport\Fotmob\FotmobClient;
use App\DataImport\RawImporter;
use App\Entity\ImportBatch;
use App\Football\Assessment\AssessmentScope;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:import:fotmob-cl', 'Import Champions League minutes, goals and assists from FotMob (cross-check source)')]
final class ImportFotmobCommand extends Command
{
    public const DATASET = 'player_stat_lists';
    public const STATS = ['mins_played', 'goals', 'goal_assist'];
    private const TRANSFORM_VERSION = '1';

    public function __construct(
        private readonly FotmobClient $client,
        private readonly RawImporter $importer,
        private readonly AssessmentScope $scope,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        ['league' => $league, 'season' => $season] = $this->scope->fotmobCl;

        // One raw record per (statistic, player, team), exactly as listed by FotMob.
        $payloads = [];
        foreach (self::STATS as $stat) {
            foreach ($this->client->statList($league, $season, $stat) as $entry) {
                $payloads[$stat . '|' . $entry['ParticiantId'] . '|' . $entry['TeamId']] = ['StatName' => $stat] + $entry;
            }
        }

        $batch = $this->importer->import(
            new ImportBatch(
                FotmobClient::SOURCE,
                self::DATASET,
                $this->scope->season,
                $this->client->statUrl($league, $season, '{stat}'),
                self::TRANSFORM_VERSION,
                competitionCode: 'CL',
            ),
            $payloads,
            notes: ['stats' => self::STATS],
        );

        $io->success(sprintf('Batch #%d: %d stat entries (%s).', $batch->getId(), $batch->getRecordCount(), implode(', ', self::STATS)));

        return Command::SUCCESS;
    }
}
