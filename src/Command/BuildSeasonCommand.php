<?php

declare(strict_types=1);

namespace App\Command;

use App\DataImport\Normalizer\SeasonBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:build-season', 'Build players, teams and season statistics from the latest imports (safe to re-run)')]
final class BuildSeasonCommand extends Command
{
    public function __construct(private readonly SeasonBuilder $builder)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $result = $this->builder->build();

        $io->definitionList(
            ['Season' => $result['season']],
            ['Batches used' => sprintf('league #%d, CL #%d', $result['batches']['league'], $result['batches']['cl'])],
            ['League players' => $result['league_players']],
            ['CL players' => sprintf('%d (%d linked to a league player, %d CL only, %d for two CL clubs)', $result['cl_players'], $result['cl_linked'], $result['cl_only'], $result['cl_partial'])],
            ['New players' => $result['players_created']],
            ['New teams' => $result['teams_created']],
            ['New identifiers' => $result['identifiers_added']],
        );
        if ($result['rejected'] !== []) {
            $io->warning(['CL records not used:', ...$result['rejected']]);
        }
        if ($result['relinked'] !== []) {
            $io->warning(['Identifiers moved to another player:', ...$result['relinked']]);
        }
        $io->success('Season built.');

        return Command::SUCCESS;
    }
}
