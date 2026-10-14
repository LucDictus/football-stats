<?php

declare(strict_types=1);

namespace App\Command;

use App\Football\Analysis\OverlapAnalysis;
use App\Football\Analysis\OverlapMarkdown;
use App\Football\Scoring\ModelCatalog;
use App\Football\Scoring\ScoreRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand('app:analyse:overlap', 'Which information drives a model\'s final score per role, and which parts measure the same thing (roadmap 5.2)')]
final class OverlapCommand extends Command
{
    public function __construct(
        private readonly ScoreRunner $runner,
        private readonly OverlapAnalysis $analysis,
        private readonly OverlapMarkdown $markdown,
        #[Autowire('%kernel.project_dir%/docs')]
        private readonly string $docs,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('model', 'm', InputOption::VALUE_REQUIRED, 'Model id (' . implode(', ', array_keys(ModelCatalog::all())) . ')', ModelCatalog::DEFAULT)
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Markdown file to write (default: docs/overlap-<model>.md)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $model = ModelCatalog::get((string) $input->getOption('model'));
        // The run is reused when nothing changed, so the report always matches a stored run.
        ['run' => $run, 'results' => $results] = $this->runner->run($model);
        $analysis = $this->analysis->analyse($model, $results);

        $io->table(
            ['Role', 'Positional ↔ contribution r', 'Final SD', 'Largest family'],
            array_map(static fn (string $role, array $a) => [
                $role,
                $a['positional_impact_correlation'] === null ? '–' : number_format($a['positional_impact_correlation'], 2),
                number_format($a['final_sd'], 1),
                sprintf('%s %.1f%%', array_key_first($a['families']), ($a['families'][array_key_first($a['families'])] ?? 0.0) * 100),
            ], array_keys($analysis['roles']), $analysis['roles']),
        );

        $file = (string) ($input->getOption('output') ?? "{$this->docs}/overlap-{$model->id()}.md");
        file_put_contents($file, $this->markdown->render($model->id(), (int) $run->getId(), $analysis));
        $io->success("Report for {$model->id()} (score run #{$run->getId()}) written to {$file}");

        return Command::SUCCESS;
    }
}
