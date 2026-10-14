<?php

declare(strict_types=1);

use App\View;

/** @var array $data */

$show = $_GET['show'] ?? 'all';
$filterOptions = ['all' => 'All', 'upcoming' => 'Upcoming', 'results' => 'Results'];
$show = isset($filterOptions[$show]) ? $show : 'all';

$matches = array_filter($data['matches'] ?? [], static fn (array $m) => match ($show) {
    'upcoming' => View::isUpcoming($m) || View::isLive($m),
    'results' => $m['status'] === 'FINISHED',
    default => true,
});

if ($show === 'results') {
    $matches = array_reverse($matches); // newest results first
}

// Group by stage + matchday, e.g. "League stage, matchday 3" or "Final".
$rounds = [];
foreach ($matches as $match) {
    $key = View::label($match['stage']);
    if ($match['matchday'] !== null && in_array($match['stage'], ['GROUP_STAGE', 'LEAGUE_STAGE', 'REGULAR_SEASON'], true)) {
        $key .= ', matchday ' . $match['matchday'];
    }
    $rounds[$key][] = $match;
}
?>
<nav class="filter" aria-label="Filter matches">
    <?php foreach ($filterOptions as $value => $name): ?>
        <a href="<?= View::e(View::url(['show' => $value === 'all' ? null : $value])) ?>"
           <?= $value === $show ? 'aria-current="page"' : '' ?>><?= View::e($name) ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($rounds === []): ?>
    <p class="empty">No matches for this selection. Try another filter or season.</p>
<?php endif; ?>

<?php foreach ($rounds as $round => $roundMatches): ?>
    <section class="round">
        <h2><?= View::e($round) ?></h2>
        <ol class="matches">
            <?php foreach ($roundMatches as $m):
                $score = View::score($m);
                $winner = $m['score']['winner'] ?? null;
                $live = View::isLive($m);
            ?>
                <li class="match<?= $live ? ' match--live' : '' ?>">
                    <div class="match-meta">
                        <time datetime="<?= View::e($m['utcDate']) ?>"><?= View::e(View::kickoff($m['utcDate'])) ?></time>
                        <?php if (!empty($m['group'])): ?><span><?= View::e(View::label($m['group'])) ?></span><?php endif; ?>
                        <?php if ($live): ?><span class="live">Live</span><?php endif; ?>
                        <?php if (in_array($m['status'], ['POSTPONED', 'SUSPENDED', 'CANCELLED'], true)): ?>
                            <span class="status"><?= View::e(View::label($m['status'])) ?></span>
                        <?php endif; ?>
                    </div>

                    <span class="team team--home<?= $winner === 'HOME_TEAM' ? ' team--won' : '' ?>">
                        <?= View::e(View::teamName($m['homeTeam'])) ?> <?= View::crest($m['homeTeam']) ?>
                    </span>

                    <span class="score">
                        <?php if ($score !== null): ?>
                            <?= (int) $score[0] ?><span class="dash">–</span><?= (int) $score[1] ?>
                        <?php else: ?>
                            <span class="vs">vs</span>
                        <?php endif; ?>
                    </span>

                    <span class="team team--away<?= $winner === 'AWAY_TEAM' ? ' team--won' : '' ?>">
                        <?= View::crest($m['awayTeam']) ?> <?= View::e(View::teamName($m['awayTeam'])) ?>
                    </span>

                    <?php if ($score !== null && $score[2] !== ''): ?>
                        <div class="match-note"><?= View::e($score[2]) ?></div>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    </section>
<?php endforeach; ?>
