<?php

declare(strict_types=1);

namespace App\Command;

use App\Football\Scoring\ModelCatalog;
use App\Football\Scoring\ScoreRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:score', 'Calculate a ranking with a scoring model and store it as a score run')]
final class ScoreCommand extends Command
{
    public function __construct(private readonly ScoreRunner $runner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('model', 'm', InputOption::VALUE_REQUIRED, 'Model id (' . implode(', ', array_keys(ModelCatalog::all())) . ')', ModelCatalog::DEFAULT)
            ->addOption('top', null, InputOption::VALUE_REQUIRED, 'Rows to print', '20');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $model = ModelCatalog::get((string) $input->getOption('model'));
        ['run' => $run, 'created' => $created, 'results' => $results] = $this->runner->run($model);

        $componentKeys = array_map(static fn ($c) => $c->key, $model->components);
        $rows = [];
        foreach (array_slice($results, 0, (int) $input->getOption('top')) as $r) {
            $rows[] = [
                $r->rank ?? '–',
                $r->candidate->name,
                $r->candidate->positionGroup,
                $r->candidate->team,
                number_format((float) $r->score, 1),
                ...array_map(static fn ($key) => $r->components[$key]['score'] === null ? '–' : number_format($r->components[$key]['score'], 1), $componentKeys),
                number_format($r->confidence * 100) . '%',
            ];
        }
        $io->table(['#', 'Player', 'Pos', 'Club', 'Score', ...array_map(static fn ($c) => $c->label, $model->components), 'Confidence'], $rows);

        $io->success($created
            ? sprintf('Score run #%d (%s): %d ranked, %d listed without rank.', $run->getId(), $model->id(), $run->getRankedCount(), $run->getUnrankedCount())
            : sprintf('Same model, data and result as score run #%d: nothing new stored (reproducible).', $run->getId()));

        return Command::SUCCESS;
    }
}
