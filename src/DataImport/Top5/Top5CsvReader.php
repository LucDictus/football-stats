<?php

declare(strict_types=1);

namespace App\DataImport\Top5;

/**
 * Reads data/master/player_seasons.csv from the Top-5 Football Dataset
 * (https://github.com/m-mahadi/top5-football-dataset): FBref + Understat + SofaScore,
 * one row per player per club stint.
 */
final readonly class Top5CsvReader
{
    public const SOURCE = 'top5-football-dataset';
    public const DATASET = 'player_seasons';
    public const URL = 'https://github.com/m-mahadi/top5-football-dataset';
    private const CSV = '/data/master/player_seasons.csv';

    public function __construct(private string $directory)
    {
    }

    public function csvPath(): string
    {
        return $this->directory . self::CSV;
    }

    /** Git commit of the local clone, so a batch can be traced to an exact dataset version. */
    public function version(): ?string
    {
        $head = @file_get_contents($this->directory . '/.git/HEAD');
        if ($head === false) {
            return null;
        }
        $head = trim($head);
        if (!str_starts_with($head, 'ref: ')) {
            return $head;
        }
        $commit = @file_get_contents($this->directory . '/.git/' . substr($head, 5));

        return $commit !== false ? trim($commit) : null;
    }

    /**
     * Stint rows of one season, keyed by "player|born|team|league" (unique per stint).
     * Values are kept as the CSV strings; '' means the source has no value.
     *
     * @param string $seasonCode season value used in the CSV, e.g. '2526'
     *
     * @return array{rows: array<string, array<string, string>>, duplicates: list<string>}
     */
    public function read(string $seasonCode): array
    {
        $fh = @fopen($this->csvPath(), 'r');
        if ($fh === false) {
            throw new \RuntimeException(sprintf('Cannot open %s. Clone the dataset first: git clone %s %s', $this->csvPath(), self::URL, $this->directory));
        }

        $header = fgetcsv($fh, escape: '');
        $rows = [];
        $duplicates = [];
        while (($line = fgetcsv($fh, escape: '')) !== false) {
            if ($line === [null]) {
                continue;
            }
            $row = array_combine($header, $line);
            if ($row['season'] !== $seasonCode) {
                continue;
            }
            $key = implode('|', [$row['player'], $row['born'], $row['team'], $row['league']]);
            if (isset($rows[$key])) {
                $duplicates[] = $key;
                continue;
            }
            $rows[$key] = $row;
        }
        fclose($fh);

        return ['rows' => $rows, 'duplicates' => $duplicates];
    }
}
