<?php

declare(strict_types=1);

/*
 * Field coverage report: per field and source, which competitions are covered and
 * how many records are missing. Saved to the field_coverage table and
 * var/export/field_coverage.csv.
 *
 *   php bin/coverage.php [--min-minutes=900]
 */

use App\CoverageReport;
use App\Database;

require __DIR__ . '/../bootstrap.php';

$options = getopt('', ['min-minutes:']);
$minMinutes = (int) ($options['min-minutes'] ?? 900);

$dbFile = __DIR__ . '/../var/data.sqlite';
if (!is_file($dbFile)) {
    fwrite(STDERR, "No database yet. Run bin/import.php and bin/import-top5.php first.\n");
    exit(1);
}

$db = Database::connect($dbFile);
$report = new CoverageReport($db, require __DIR__ . '/../config/fields.php', $minMinutes);
$rows = $report->build();
$report->save($rows);

// CSV
$dir = __DIR__ . '/../var/export';
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}
$fh = fopen($dir . '/field_coverage.csv', 'w');
fputcsv($fh, array_keys($rows[0]), escape: '');
foreach ($rows as $row) {
    fputcsv($fh, $row, escape: '');
}
fclose($fh);

// Console table
$cut = static fn (string $s, int $n): string => strlen($s) > $n ? substr($s, 0, $n - 1) . '~' : $s;
$category = null;
foreach ($rows as $r) {
    if ($r['category'] !== $category) {
        $category = $r['category'];
        printf("\n%s (%s)\n", strtoupper($category), $r['priority']);
        printf("  %-24s %-6s %-22s %-17s %16s %18s  %s\n", 'Field', 'Scope', 'Source column', 'Competitions', 'Missing', "Missing >={$minMinutes}'", 'Status');
    }
    $missing = $r['records_missing'] === null
        ? ($r['records_in_scope'] !== null ? 'unknown' : '-')
        : sprintf('%d/%d (%s%%)', $r['records_missing'], $r['records_in_scope'], $r['missing_pct']);
    printf(
        "  %-24s %-6s %-22s %-17s %16s %18s  %s\n",
        $cut($r['label'], 24),
        $r['applies_to'],
        $cut($r['source_column'] ?: '-', 22),
        $r['competitions_with_data'] ?: ($r['competitions_planned'] ? "({$r['competitions_planned']})" : '-'),
        $missing,
        $r['missing_min_minutes'] ?? '-',
        $r['status']
    );
}

echo "\nSaved to table field_coverage and var/export/field_coverage.csv\n";
