<?php

declare(strict_types=1);

namespace App\DataImport\Sofascore;

use App\DataImport\SourceName;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * SofaScore's public (unofficial) web API: season statistics per player for a tournament.
 *
 * SofaScore rejects PHP's HTTP stack (TLS fingerprint) with a 403 but accepts the curl
 * binary, so pages are fetched with curl and saved as snapshot files. Imports read the
 * snapshots, which also makes them reproducible. If curl gets blocked too, the pages can
 * be saved from a browser into the snapshot directory (page-1.json, page-2.json, ...).
 */
final readonly class SofascoreClient
{
    public const SOURCE = SourceName::SOFASCORE;
    public const DATASET = 'player_season_statistics';
    private const BASE_URL = 'https://api.sofascore.com/api/v1';
    private const PAGE_SIZE = 100;

    /** Same names as the ss_* columns of the Top-5 dataset, so league and CL data line up. */
    public const FIELDS = [
        'minutesPlayed', 'appearances', 'matchesStarted', 'goals', 'assists', 'penaltyGoals',
        'expectedGoals', 'expectedAssists', 'totalShots', 'shotsOnTarget', 'keyPasses', 'bigChancesCreated',
        'accuratePasses', 'accuratePassesPercentage', 'accurateFinalThirdPasses', 'tackles', 'tacklesWon',
        'interceptions', 'outfielderBlocks', 'clearances', 'ballRecovery', 'aerialDuelsWon', 'aerialLost',
        'errorLeadToGoal', 'saves', 'goalsConceded', 'cleanSheet', 'goalsPrevented', 'rating',
    ];

    /**
     * The statistics endpoint has no position. A player who made saves, or has a
     * goals-prevented value, is a goalkeeper; outfield players have neither.
     *
     * @param array<string, mixed> $record
     */
    public static function looksLikeGoalkeeper(array $record): bool
    {
        return ($record['goalsPrevented'] ?? null) !== null || ($record['saves'] ?? 0) > 0;
    }

    public function __construct(
        private Filesystem $filesystem = new Filesystem(),
        private float $pauseSeconds = 1.0,
    ) {
    }

    public function statisticsUrl(int $tournamentId, int $seasonId, int $page = 1): string
    {
        return sprintf('%s/unique-tournament/%d/season/%d/statistics?', self::BASE_URL, $tournamentId, $seasonId)
            . http_build_query([
                'limit' => self::PAGE_SIZE,
                'offset' => ($page - 1) * self::PAGE_SIZE,
                'order' => '-minutesPlayed',
                'accumulation' => 'total',
                'fields' => implode(',', self::FIELDS),
            ], encoding_type: PHP_QUERY_RFC3986);
    }

    /**
     * Downloads every statistics page into $directory as page-N.json and returns the page
     * count. Pages go to a temporary directory first, so a failed download never replaces
     * (or leaves behind) a partial snapshot.
     */
    public function fetchSnapshot(int $tournamentId, int $seasonId, string $directory): int
    {
        $tmp = $directory . '.download';
        $this->filesystem->remove($tmp);
        $this->filesystem->mkdir($tmp);

        try {
            for ($page = 1, $pages = 1; $page <= $pages; ++$page) {
                if ($page > 1) {
                    usleep((int) ($this->pauseSeconds * 1_000_000));
                }
                $process = new Process(['curl', '--silent', '--fail', '--compressed', $this->statisticsUrl($tournamentId, $seasonId, $page)]);
                $process->mustRun();
                $data = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                $this->filesystem->dumpFile(sprintf('%s/page-%d.json', $tmp, $page), $process->getOutput());
                $pages = (int) ($data['pages'] ?? 1);
            }
        } catch (\Throwable $e) {
            $this->filesystem->remove($tmp);
            throw $e;
        }

        $this->filesystem->remove($directory);
        $this->filesystem->rename($tmp, $directory);

        return $pages;
    }

    /**
     * All result objects (one per player and team) from a snapshot directory.
     *
     * @return list<array<string, mixed>>
     */
    public function readSnapshot(string $directory): array
    {
        $files = glob($directory . '/page-*.json') ?: [];
        if ($files === []) {
            throw new \RuntimeException("No snapshot pages in {$directory}");
        }
        natsort($files);

        $results = [];
        $expectedPages = null;
        foreach ($files as $file) {
            $data = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            $expectedPages ??= (int) ($data['pages'] ?? 0);
            array_push($results, ...$data['results'] ?? []);
        }
        if ($expectedPages !== count($files)) {
            throw new \RuntimeException(sprintf('Incomplete snapshot in %s: %d of %d pages', $directory, count($files), $expectedPages));
        }

        return $results;
    }
}
