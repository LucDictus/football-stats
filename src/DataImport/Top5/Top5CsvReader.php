<?php

declare(strict_types=1);

namespace App\DataImport\Top5;

use App\Football\Data\FieldCatalog;
use App\Football\Data\Origin;

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
     * @return array{rows: array<string, array<string, string>>, rejected: list<string>}
     */
    public function read(string $seasonCode): array
    {
        $fh = @fopen($this->csvPath(), 'r');
        if ($fh === false) {
            throw new \RuntimeException(sprintf('Cannot open %s. Clone the dataset first: git clone %s %s', $this->csvPath(), self::URL, $this->directory));
        }

        try {
            $header = fgetcsv($fh, escape: '');
            if ($header === false) {
                throw new \RuntimeException("{$this->csvPath()} is empty");
            }
            $header = array_map('strval', $header);
            $missing = array_diff(self::requiredColumns(), $header);
            if ($missing !== []) {
                throw new \RuntimeException(sprintf('%s lacks columns the pipeline needs: %s. Did the dataset change?', $this->csvPath(), implode(', ', $missing)));
            }

            $rows = [];
            $rejected = [];
            for ($lineNumber = 2; ($line = fgetcsv($fh, escape: '')) !== false; ++$lineNumber) {
                if ($line === [null]) {
                    continue;
                }
                if (count($line) !== count($header)) {
                    $rejected[] = sprintf('line %d: %d columns instead of %d', $lineNumber, count($line), count($header));
                    continue;
                }
                $row = array_combine($header, array_map('strval', $line));
                if ($row['season'] !== $seasonCode) {
                    continue;
                }
                $key = implode('|', [$row['player'], $row['born'], $row['team'], $row['league']]);
                if (isset($rows[$key])) {
                    $rejected[] = "line {$lineNumber}: duplicate of {$key}";
                    continue;
                }
                $rows[$key] = $row;
            }
        } finally {
            fclose($fh);
        }

        return ['rows' => $rows, 'rejected' => $rejected];
    }

    /** @return list<string> identity columns plus every league column in the field catalog */
    public static function requiredColumns(): array
    {
        $columns = ['season', 'league', 'team', 'player', 'born', 'pos', 'ss_player_id', 'ss_goals'];
        foreach (Origin::providers() as $origin) {
            $columns[] = (string) $origin->leagueMinutesColumn();
        }
        foreach (FieldCatalog::all() as $field) {
            if ($field->leagueColumn !== null) {
                $columns[] = $field->leagueColumn;
            }
        }

        return array_values(array_unique($columns));
    }
}
