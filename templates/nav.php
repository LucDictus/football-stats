<?php

declare(strict_types=1);

use App\View;

/**
 * Shared top navigation.
 *
 * @var string      $activePage        'competition' | 'players' | 'coverage'
 * @var string|null $activeCompetition competition code when $activePage = 'competition'
 */
$activeCompetition ??= null;
?>
<nav class="competitions" aria-label="Main">
    <?php foreach (COMPETITIONS as $c => $name): ?>
        <a href="index.php?competition=<?= View::e($c) ?>"
           <?= $activePage === 'competition' && $c === $activeCompetition ? 'aria-current="page"' : '' ?>><?= View::e($name) ?></a>
    <?php endforeach; ?>
    <span class="nav-data">
        <a href="players.php" <?= $activePage === 'players' ? 'aria-current="page"' : '' ?>>Players</a>
        <a href="coverage.php" <?= $activePage === 'coverage' ? 'aria-current="page"' : '' ?>>Data coverage</a>
    </span>
</nav>
