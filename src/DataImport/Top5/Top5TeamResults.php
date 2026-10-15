<?php

declare(strict_types=1);

namespace App\DataImport\Top5;

/**
 * Reads data/schedule/matches.csv from the Top-5 Football Dataset: every league match with its
 * score. Team names are FBref's, as in the player data.
 */
final readonly class Top5TeamResults
{
    private const CSV = '/data/schedule/matches.csv';

    public function __construct(private string $directory)
    {
    }

    /**
     * Goal difference per match of every team in a season, from its played matches.
     *
     * @param string $seasonCode season value used in the CSV, e.g. '2526'
     *
     * @return array<string, array{goal_difference: float, matches: int}> keyed by "league|team"
     */
    public function goalDifference(string $seasonCode): array
    {
        $fh = @fopen($this->directory . self::CSV, 'r');
        if ($fh === false) {
            throw new \RuntimeException("Cannot open {$this->directory}" . self::CSV);
        }
        $teams = [];
        try {
            $header = fgetcsv($fh, escape: '');
            if ($header === false) {
                throw new \RuntimeException('matches.csv is empty');
            }
            while (($line = fgetcsv($fh, escape: '')) !== false) {
                if (count($line) !== count($header)) {
                    continue;
                }
                $m = array_combine(array_map('strval', $header), array_map('strval', $line));
                // Played matches only: "2–1" (en dash); postponed and future matches have no score.
                if ($m['season'] !== $seasonCode || preg_match('/^(\d+)–(\d+)$/u', $m['score'], $score) !== 1) {
                    continue;
                }
                foreach ([[$m['home_team'], (int) $score[1] - (int) $score[2]], [$m['away_team'], (int) $score[2] - (int) $score[1]]] as [$team, $difference]) {
                    $key = $m['league'] . '|' . $team;
                    $teams[$key] ??= ['difference' => 0, 'matches' => 0];
                    $teams[$key]['difference'] += $difference;
                    ++$teams[$key]['matches'];
                }
            }
        } finally {
            fclose($fh);
        }

        return array_map(static fn (array $t) => ['goal_difference' => $t['difference'] / $t['matches'], 'matches' => $t['matches']], $teams);
    }
}
