<?php

declare(strict_types=1);

/*
 * Exports the imported data to CSV in var/export/.
 *
 *   php bin/export.php
 */

use App\Database;

require __DIR__ . '/../bootstrap.php';

$dbFile = __DIR__ . '/../var/data.sqlite';
if (!is_file($dbFile)) {
    fwrite(STDERR, "No database yet. Run php bin/import.php first.\n");
    exit(1);
}

$db = Database::connect($dbFile);
$dir = __DIR__ . '/../var/export';
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}

$exports = [
    'match_records.csv' => 'SELECT * FROM match_records ORDER BY utc_date, competition_code',
    'player_competition_stats.csv' =>
        'SELECT p.id AS player_id, p.name AS player, p.nationality, p.position,
                c.code AS competition_code, substr(se.start_date, 1, 4) AS season,
                t.name AS team, s.played_matches, s.goals, s.assists, s.penalties, s.source
         FROM player_competition_stats s
         JOIN players p ON p.id = s.player_id
         JOIN competitions c ON c.id = s.competition_id
         JOIN seasons se ON se.id = s.season_id
         LEFT JOIN teams t ON t.id = s.team_id
         ORDER BY c.code, s.goals DESC',
    'import_log.csv' => 'SELECT * FROM import_log ORDER BY id',
];

foreach ($exports as $file => $sql) {
    $rows = $db->query($sql)->fetchAll();
    $fh = fopen("{$dir}/{$file}", 'w');
    if ($rows !== []) {
        fputcsv($fh, array_keys($rows[0]), escape: '');
        foreach ($rows as $row) {
            fputcsv($fh, $row, escape: '');
        }
    }
    fclose($fh);
    printf("%-30s %5d rows\n", $file, count($rows));
}
