<?php

declare(strict_types=1);

use App\FootballDataClient;
use App\FootballDataException;
use App\View;

require __DIR__ . '/../bootstrap.php';

define('COMPETITIONS', require __DIR__ . '/../config/competitions.php');

const VIEWS = [
    'matches' => 'Matches',
    'standings' => 'Standings',
    'scorers' => 'Top scorers',
];

$code = strtoupper($_GET['competition'] ?? 'CL');
$code = isset(COMPETITIONS[$code]) ? $code : 'CL';

$view = $_GET['view'] ?? 'matches';
$view = isset(VIEWS[$view]) ? $view : 'matches';

$scope = require __DIR__ . '/../config/scope.php';
$period = $scope['period'];

// Default to the assessment season from config/scope.php; ?season=YYYY overrides it.
$defaultSeason = (string) ($scope['competitions'][$code][0] ?? '');
$season = preg_match('/^\d{4}$/', $_GET['season'] ?? '') ? $_GET['season'] : $defaultSeason;
$filters = $season !== '' ? ['season' => $season] : [];

View::$timezone = env('TIMEZONE', 'Europe/Amsterdam');

$data = null;
$error = null;

try {
    $apiKey = env('FOOTBALL_DATA_API_KEY');
    if ($apiKey === null) {
        throw new FootballDataException('No API key found. Copy .env.example to .env and set FOOTBALL_DATA_API_KEY.');
    }

    $client = new FootballDataClient($apiKey, __DIR__ . '/../var/cache', (int) env('CACHE_TTL', '300'));

    $data = match ($view) {
        'matches' => $client->matches($code, $filters),
        'standings' => $client->standings($code, $filters),
        'scorers' => $client->scorers($code, $filters + ['limit' => 20]),
    };
} catch (FootballDataException $e) {
    $error = match ($e->getCode()) {
        400 => 'The API rejected the request: ' . $e->getMessage(),
        403 => 'Your plan has no access to this resource (or this season). ' . $e->getMessage(),
        429 => 'Rate limit reached (10 requests per minute on the free plan). Wait a minute and reload.',
        default => $e->getMessage(),
    };
}

$competition = $data['competition'] ?? null;
$returnedSeason = $data['season'] ?? $data['matches'][0]['season'] ?? null;
$seasonLabel = View::season($returnedSeason);

// The API can fall back to another season (e.g. when a past season isn't in your plan).
$seasonWarning = null;
if ($returnedSeason !== null && $season !== '' && substr($returnedSeason['startDate'], 0, 4) !== $season) {
    $seasonWarning = sprintf(
        'You asked for season %s, but the API returned season %s. This season may not be available on your plan.',
        $season,
        $seasonLabel
    );
}

// Only show matches inside the assessment period (unless another season was picked).
if ($view === 'matches' && $data !== null && $season === $defaultSeason) {
    $data['matches'] = array_values(array_filter(
        $data['matches'] ?? [],
        static fn (array $m) => substr($m['utcDate'], 0, 10) >= $period['from'] && substr($m['utcDate'], 0, 10) <= $period['to']
    ));
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= View::e(COMPETITIONS[$code] . ' – ' . VIEWS[$view]) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;700&family=Barlow:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>
<header class="site-header">
    <?php
    $activePage = 'competition';
    $activeCompetition = $code;
    require __DIR__ . '/../templates/nav.php';
    ?>

    <div class="title">
        <?php if (!empty($competition['emblem'])): ?>
            <img class="emblem" src="<?= View::e($competition['emblem']) ?>" alt="" width="56" height="56">
        <?php endif; ?>
        <h1>
            <?= View::e($competition['name'] ?? COMPETITIONS[$code]) ?>
            <?php if ($seasonLabel !== ''): ?><span class="season"><?= View::e($seasonLabel) ?></span><?php endif; ?>
        </h1>
    </div>

    <div class="toolbar">
        <nav class="views" aria-label="View">
            <?php foreach (VIEWS as $v => $name): ?>
                <a href="<?= View::e(View::url(['view' => $v, 'show' => null])) ?>"
                   <?= $v === $view ? 'aria-current="page"' : '' ?>><?= View::e($name) ?></a>
            <?php endforeach; ?>
        </nav>

        <form class="season-form" method="get">
            <input type="hidden" name="competition" value="<?= View::e($code) ?>">
            <input type="hidden" name="view" value="<?= View::e($view) ?>">
            <label for="season">Season start year</label>
            <input id="season" name="season" inputmode="numeric" pattern="\d{4}"
                   value="<?= View::e($season) ?>" size="7">
            <button type="submit">Show</button>
        </form>
    </div>
</header>

<main>
    <?php if ($season === $defaultSeason): ?>
        <p class="period">Assessment period: <?= View::e(date('j M Y', strtotime($period['from']))) ?> to <?= View::e(date('j M Y', strtotime($period['to']))) ?></p>
    <?php endif; ?>
    <?php if ($seasonWarning !== null): ?>
        <p class="error"><?= View::e($seasonWarning) ?></p>
    <?php endif; ?>
    <?php if ($error !== null): ?>
        <p class="error"><?= View::e($error) ?></p>
    <?php else: ?>
        <?php require __DIR__ . '/../templates/' . $view . '.php'; ?>
    <?php endif; ?>
</main>

<footer>
    Data from football-data.org. Times shown in <?= View::e(View::$timezone) ?>.
</footer>
</body>
</html>
