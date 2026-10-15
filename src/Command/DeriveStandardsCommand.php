<?php

declare(strict_types=1);

namespace App\Command;

use App\DataImport\Top5\Top5StandardsSample;
use App\Football\Standards\StandardsDeriver;
use App\Football\Standards\StandardsFile;
use App\Football\Standards\StandardsMarkdown;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand('app:standards:derive', 'Derive the positional standards (which statistics count per role, and how much) from two seasons of data')]
final class DeriveStandardsCommand extends Command
{
    public function __construct(
        private readonly Top5StandardsSample $sample,
        private readonly StandardsDeriver $deriver,
        private readonly StandardsMarkdown $markdown,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('seasons', null, InputOption::VALUE_REQUIRED, 'Two Top-5 dataset season codes, in time order', '2425,2526')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Name of the standards file (config/standards/<id>.json)', 'standards-v1')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print only, write nothing')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite existing standards with different numbers (only while no released model uses them)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $codes = array_map('trim', explode(',', (string) $input->getOption('seasons')));
        if (count($codes) !== 2) {
            throw new \InvalidArgumentException('Give exactly two seasons, e.g. 2425,2526');
        }

        $seasons = [];
        foreach ($codes as $code) {
            $seasons[$code] = $this->sample->load($code);
            $io->writeln(sprintf('Season %s: %d player seasons', $code, count($seasons[$code])));
        }
        $standards = $this->deriver->derive($seasons);
        $validation = $this->deriver->validate($seasons);

        foreach ($standards as $role => $standard) {
            $io->section(sprintf('%s: %d players (%s), %d in both seasons', $role, $standard['players'], implode(' + ', $standard['seasons']), $standard['pairs']));
            $io->table(['Weight', 'Link with winning', 'Repeatability', 'Measure'], array_map(
                static fn (array $m) => [sprintf('%.0f%%', $m['weight'] * 100), number_format($m['relevance'], 2), number_format($m['reliability'], 2), $m['label']],
                $standard['measures'],
            ));
            if ($output->isVerbose()) {
                $io->table(['Statistic', 'Players', 'Link per season', 'Repeatability', 'Used', 'Why not'], array_map(
                    static fn (array $s) => [$s['label'], $s['players'], implode(' / ', array_map(static fn (float $r) => number_format($r, 2), $s['relevance'])), self::r($s['reliability']), $s['used'] ? 'yes' : '', $s['reason'] ?? ''],
                    $standard['statistics'],
                ));
            }
        }
        $io->section('Validation (derived from the first season, tested on the second)');
        $io->table(['Method', 'Team results r', 'beyond previous', 'Teams', 'Repeatability', 'Players'], array_map(
            static fn (string $scheme, array $v) => [$scheme, self::r($v['team_r']), self::r($v['team_r_beyond_previous']), $v['teams'], self::r($v['repeatability']), $v['players']],
            array_keys($validation),
            $validation,
        ));

        if ($input->getOption('dry-run')) {
            return Command::SUCCESS;
        }
        $id = (string) $input->getOption('id');
        $file = "{$this->projectDir}/config/standards/{$id}.json";
        if (!StandardsFile::write($file, StandardsFile::encode($id, $codes, $standards, $validation), (bool) $input->getOption('force'))) {
            $io->error("{$id} exists with different numbers. A model built on it would change: derive under a new --id (and a new model version), or pass --force while no released model uses it.");

            return Command::FAILURE;
        }
        file_put_contents("{$this->projectDir}/docs/standards.md", $this->markdown->render(StandardsFile::read($file)));
        $io->success("Standards written to {$file} and docs/standards.md");

        return Command::SUCCESS;
    }

    private static function r(?float $r): string
    {
        return $r === null ? '–' : number_format($r, 3);
    }
}
