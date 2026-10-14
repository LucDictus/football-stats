<?php

declare(strict_types=1);

use App\CoverageReport;
use App\Database;
use App\View;

require __DIR__ . '/../bootstrap.php';

define('COMPETITIONS', require __DIR__ . '/../config/competitions.php');

$minMinutes = 900;
$rows = [];
$dbFile = __DIR__ . '/../var/data.sqlite';
if (is_file($dbFile)) {
    $report = new CoverageReport(Database::connect($dbFile), require __DIR__ . '/../config/fields.php', $minMinutes);
    $rows = $report->build();
}

$statusClass = static fn (string $status): string => match (true) {
    $status === 'complete' => 'ok',
    str_starts_with($status, 'partial') => 'partial',
    default => 'none',
};

$pageTitle = 'Data coverage';
$activePage = 'coverage';
require __DIR__ . '/../templates/head.php';
?>
<body class="page-wide">
<header class="site-header">
    <?php require __DIR__ . '/../templates/nav.php'; ?>
    <div class="title">
        <h1>Data coverage <span class="season">Which source provides each field, and how much is missing</span></h1>
    </div>
</header>

<main>
<?php if ($rows === []): ?>
    <p class="error">No data imported yet. Run bin/import.php and bin/import-top5.php first.</p>
<?php else: ?>
    <p class="table-summary">
        "Missing" counts players without a value, never zeros. Goalkeeping fields are counted over
        goalkeepers only. The last count is limited to players with <?= $minMinutes ?>+ league minutes.
    </p>
    <div class="wide-table-wrap">
        <table class="data-table coverage-table">
            <thead>
            <tr class="col-head">
                <th>Field</th>
                <th>Priority</th>
                <th>Scope</th>
                <th>Source</th>
                <th>Column</th>
                <th>Level</th>
                <th>Competitions with data</th>
                <th>Missing</th>
                <th class="num">Missing (<?= $minMinutes ?>+ min)</th>
                <th>Status</th>
                <th>Note</th>
            </tr>
            </thead>
            <?php
            $category = null;
            foreach ($rows as $r):
                if ($r['category'] !== $category):
                    if ($category !== null) echo '</tbody>';
                    $category = $r['category']; ?>
                    <tbody>
                    <tr class="group-row"><th colspan="11"><?= View::e($category) ?></th></tr>
                <?php endif; ?>
                <tr>
                    <td><?= View::e($r['label']) ?></td>
                    <td><?= View::e($r['priority']) ?></td>
                    <td><?= $r['applies_to'] === 'GK' ? 'Goalkeepers' : 'All' ?></td>
                    <td><?= View::e($r['source']) ?></td>
                    <td class="source"><?= View::e($r['source_column'] ?: '–') ?></td>
                    <td><?= View::e($r['level'] ?: '–') ?></td>
                    <td><?= View::e($r['competitions_with_data'] ?: ($r['competitions_planned'] ? 'none (' . $r['competitions_planned'] . ')' : '–')) ?></td>
                    <td class="missing">
                        <?php if ($r['records_missing'] !== null): ?>
                            <span class="bar"><span style="width: <?= min(100, (float) $r['missing_pct']) ?>%"></span></span>
                            <?= number_format((int) $r['records_missing']) ?> / <?= number_format((int) $r['records_in_scope']) ?>
                            <span class="muted">(<?= View::e($r['missing_pct']) ?>%)</span>
                        <?php elseif ($r['records_in_scope'] !== null): ?>
                            unknown <span class="muted">(<?= number_format((int) $r['records_in_scope']) ?> rows)</span>
                        <?php else: ?>
                            –
                        <?php endif; ?>
                    </td>
                    <td class="num"><?= View::e($r['missing_min_minutes'] ?? '–') ?></td>
                    <td><span class="status status--<?= $statusClass($r['status']) ?>"><?= View::e($r['status']) ?></span></td>
                    <td class="note-cell"><?= View::e($r['note']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
</main>

<footer>Generated live from config/fields.php and the imported data. Also available as CSV via php bin/coverage.php.</footer>
</body>
</html>
