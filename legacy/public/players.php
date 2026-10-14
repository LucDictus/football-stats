<?php

declare(strict_types=1);

use App\Database;
use App\PlayerRepository;
use App\PlayerTable;
use App\View;

require __DIR__ . '/../bootstrap.php';

define('COMPETITIONS', require __DIR__ . '/../config/competitions.php');

$dbFile = __DIR__ . '/../var/data.sqlite';
$table = null;
$error = null;

if (is_file($dbFile)) {
    $db = Database::connect($dbFile);
    $table = new PlayerTable($db, require __DIR__ . '/../config/fields.php');
    if (!$table->isImported()) {
        $error = 'import-top5';
    }
} else {
    $error = 'import-top5';
}

$playerId = ctype_digit($_GET['id'] ?? '') ? (int) $_GET['id'] : null;
$player = null;

if ($error === null && $playerId !== null) {
    $player = $table->find($playerId);
    if ($player === null) {
        $error = 'not-found';
    }
}

// Filters for the table (all via the URL, so a view can be bookmarked or shared)
$filters = [
    'league' => isset(PlayerTable::LEAGUES[$_GET['league'] ?? '']) ? $_GET['league'] : 'all',
    'position' => isset(PlayerTable::POSITIONS[$_GET['position'] ?? '']) ? $_GET['position'] : 'all',
    'min_minutes' => isset($_GET['min']) && ctype_digit($_GET['min']) ? (int) $_GET['min'] : 900,
    'q' => trim((string) ($_GET['q'] ?? '')),
    'sort' => (string) ($_GET['sort'] ?? 'goals'),
    'dir' => ($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc',
    'per90' => ($_GET['per90'] ?? '') === '1',
    'page' => max(1, (int) ($_GET['page'] ?? 1)),
];

$pageTitle = $player['player'] ?? 'Players';
$activePage = 'players';
require __DIR__ . '/../templates/head.php';
?>
<body class="page-wide">
<header class="site-header">
    <?php require __DIR__ . '/../templates/nav.php'; ?>
    <div class="title">
        <h1>
            <?= View::e($pageTitle) ?>
            <span class="season">Top-5 leagues 2025/26, plus Champions League and World Cup top scorers</span>
        </h1>
    </div>
</header>

<main>
    <?php if ($error === 'import-top5'): ?>
        <div class="error">
            <p>The player statistics haven't been imported yet. From the project root:</p>
            <pre>git clone https://github.com/m-mahadi/top5-football-dataset var/sources/top5-football-dataset
docker compose run --rm app php bin/import-top5.php</pre>
        </div>
    <?php elseif ($error === 'not-found'): ?>
        <p class="error">This player isn't in the dataset. <a href="players.php">Back to all players</a></p>
    <?php elseif ($player !== null): ?>
        <?php
        $repo = new PlayerRepository($db);
        require __DIR__ . '/../templates/player-detail.php';
        ?>
    <?php else: ?>
        <?php
        $result = $table->fetch($filters);
        require __DIR__ . '/../templates/player-table.php';
        ?>
    <?php endif; ?>
</main>

<footer>
    Sources: FBref, Understat and SofaScore via the Top-5 Football Dataset; football-data.org for
    Champions League and World Cup top scorers. A dash means the source has no value, not zero.
    Field-by-field details are on the <a href="coverage.php">data coverage</a> page.
</footer>
</body>
</html>
