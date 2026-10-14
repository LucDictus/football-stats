<?php

declare(strict_types=1);

namespace App\Command;

use App\DataQuality\DataAudit;
use App\DataQuality\DataAuditMarkdown;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand('app:data:audit', 'Check coverage and consistency of the imported data and write the data coverage report')]
final class DataAuditCommand extends Command
{
    public function __construct(
        private readonly DataAudit $audit,
        private readonly DataAuditMarkdown $markdown,
        #[Autowire('%kernel.project_dir%/docs/data-coverage.md')]
        private readonly string $defaultOutput,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Markdown file to write', $this->defaultOutput);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $result = $this->audit->run();

        $io->section('Provider consistency (players with ' . DataAudit::LEAGUE_MIN_MINUTES . '+ league minutes)');
        $rows = [];
        foreach ($result['origins'] as $origin => $row) {
            $rows[] = [$origin, ...array_values($row['regular_counts'])];
        }
        $io->table(['Provider', 'ok', 'partial', 'suspect', 'missing'], $rows);

        $io->section('Coverage matrix');
        $io->table(
            ['Statistic', 'Present', 'Trusted', 'Verdict'],
            array_map(static fn (array $r) => [$r['field']->label, $r['present_pct'] . '%', $r['trusted_pct'] . '%', $r['verdict']], $result['fields']),
        );

        $file = (string) $input->getOption('output');
        file_put_contents($file, $this->markdown->render($result));
        $io->success("Report written to {$file}");

        return Command::SUCCESS;
    }
}
