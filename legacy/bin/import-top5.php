<?php

declare(strict_types=1);

/*
 * Imports the Top-5 Football Dataset (season 2025/26) into var/data.sqlite.
 *
 *   git clone https://github.com/m-mahadi/top5-football-dataset var/sources/top5-football-dataset
 *   php bin/import-top5.php [path-to-the-clone]
 */

use App\Database;
use App\PlayerLinker;
use App\Top5Importer;

require __DIR__ . '/../bootstrap.php';

$dir = rtrim($argv[1] ?? __DIR__ . '/../var/sources/top5-football-dataset', '/');
$csv = $dir . '/data/master/player_seasons.csv';

if (!is_file($csv)) {
    fwrite(STDERR, "Not found: {$csv}\nClone the dataset first:\n"
        . "  git clone https://github.com/m-mahadi/top5-football-dataset var/sources/top5-football-dataset\n");
    exit(1);
}

// Record which version of the dataset was imported (git commit), for provenance.
$version = null;
if (is_file($dir . '/.git/HEAD')) {
    $head = trim((string) file_get_contents($dir . '/.git/HEAD'));
    $version = str_starts_with($head, 'ref: ')
        ? trim((string) @file_get_contents($dir . '/.git/' . substr($head, 5))) ?: $head
        : $head;
}

$db = Database::connect(__DIR__ . '/../var/data.sqlite');
Database::migrate($db, __DIR__ . '/../database/schema.sql');

$importer = new Top5Importer($db, require __DIR__ . '/../config/fields.php');
$result = $importer->import($csv, '2526', 2025, $version);

printf("Top-5 dataset %s\n", $version !== null ? '(commit ' . substr($version, 0, 10) . ')' : '');
printf("  %d stint rows -> %d players (%d merged from several clubs)\n", $result['rows'], $result['players'], $result['merged']);
if ($result['conflicts'] !== []) {
    printf("  %d season values differed between stints (stint with most minutes used):\n", count($result['conflicts']));
    foreach (array_slice($result['conflicts'], 0, 10) as $c) {
        echo "    {$c}\n";
    }
}
$links = PlayerLinker::run($db);
if ($links !== null) {
    printf("  Linked %d football-data.org players (CL/WC top scorers) to dataset players, %d unmatched\n",
        $links['linked'], count($links['unmatched']));
}

echo "\nRun php bin/coverage.php for the field coverage report.\n";
