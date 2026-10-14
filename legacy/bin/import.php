<?php

declare(strict_types=1);

/*
 * Imports all competitions in config/scope.php into var/data.sqlite.
 *
 *   php bin/import.php                 # everything in scope
 *   php bin/import.php --only=CL,WC    # a subset
 *   php bin/import.php --fresh         # ignore the response cache
 */

use App\Database;
use App\FootballDataClient;
use App\Importer;
use App\PlayerLinker;

require __DIR__ . '/../bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}

$options = getopt('', ['only:', 'fresh']);
$scope = require __DIR__ . '/../config/scope.php';

$apiKey = env('FOOTBALL_DATA_API_KEY');
if ($apiKey === null) {
    fwrite(STDERR, "No API key. Copy .env.example to .env and set FOOTBALL_DATA_API_KEY.\n");
    exit(1);
}

$competitions = $scope['competitions'];
if (isset($options['only'])) {
    $only = array_map('trim', explode(',', strtoupper((string) $options['only'])));
    $competitions = array_intersect_key($competitions, array_flip($only));
}

$db = Database::connect(__DIR__ . '/../var/data.sqlite');
Database::migrate($db, __DIR__ . '/../database/schema.sql');

// 6.5 s between real requests keeps us under 10 calls/minute.
// Finished seasons barely change, so cached responses stay valid for a day.
$client = new FootballDataClient(
    $apiKey,
    __DIR__ . '/../var/cache',
    isset($options['fresh']) ? 0 : 86400,
    6.5,
);

$importer = new Importer(
    $client,
    $db,
    $scope['period']['from'],
    $scope['period']['to'],
    $scope['scorers_limit'],
    static fn (string $line) => print($line . PHP_EOL),
);

printf("Period %s to %s, %d competition(s)\n\n", $scope['period']['from'], $scope['period']['to'], count($competitions));

$errors = 0;
foreach ($competitions as $code => $seasons) {
    foreach ($seasons as $season) {
        printf("%s, season %d\n", $code, $season);
        $stats = $importer->import($code, $season);
        if ($stats['skipped'] > 0) {
            printf("  (%d matches outside the period skipped)\n", $stats['skipped']);
        }
        $errors += $stats['errors'];
    }
}

echo "\nMatch records per competition\n";
$summary = $db->query(
    "SELECT competition_code,
            COUNT(*) AS total,
            SUM(status = 'FINISHED') AS finished,
            SUM(missing_fields <> '[]') AS with_missing,
            SUM(home_strength IS NULL OR away_strength IS NULL) AS no_strength
     FROM match_records
     GROUP BY competition_code
     ORDER BY competition_code"
)->fetchAll();

printf("  %-5s %7s %9s %13s %12s\n", 'Code', 'Matches', 'Finished', 'Missing data', 'No strength');
foreach ($summary as $r) {
    printf("  %-5s %7d %9d %13d %12d\n", $r['competition_code'], $r['total'], $r['finished'], $r['with_missing'], $r['no_strength']);
}

$links = PlayerLinker::run($db);
if ($links !== null) {
    printf("\nLinked %d players to the Top-5 dataset, %d unmatched\n", $links['linked'], count($links['unmatched']));
}

echo $errors > 0 ? "\n{$errors} request(s) failed, see the import_log table.\n" : "\nDone.\n";
exit($errors > 0 ? 2 : 0);
