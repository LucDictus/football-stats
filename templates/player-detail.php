<?php

declare(strict_types=1);

use App\PlayerRepository;
use App\PlayerTable;
use App\View;

/**
 * @var PlayerTable      $table
 * @var PlayerRepository $repo
 * @var array            $player  row from ext_player_seasons
 */

$groups = $table->groups();
unset($groups['Champions League & World Cup']); // shown separately below, per competition

$links = $table->linkedFootballDataIds((int) $player['id']);
$fdRows = [];
foreach ($links as $link) {
    foreach ($repo->breakdown((int) $link['fd_player_id']) as $r) {
        $fdRows[] = $r + ['method' => $link['method']];
    }
}

$facts = [
    'Club' => $player['teams'],
    'League' => implode(', ', array_map(
        static fn ($c) => PlayerTable::LEAGUES[$c] ?? $c,
        explode(',', (string) $player['leagues'])
    )),
    'Position' => $player['pos'],
    'Age' => $player['age'],
    'Nationality' => $player['nation'],
];
?>
<p><a href="players.php">← All players</a></p>

<dl class="player-facts">
    <?php foreach ($facts as $label => $value): ?>
        <?php if ($value !== null && $value !== ''): ?>
            <div><dt><?= View::e($label) ?></dt><dd><?= View::e($value) ?></dd></div>
        <?php endif; ?>
    <?php endforeach; ?>
    <?php if ((int) $player['stints'] > 1): ?>
        <div><dt>Note</dt><dd>Played for <?= (int) $player['stints'] ?> clubs; totals cover all of them</dd></div>
    <?php endif; ?>
</dl>

<div class="stat-groups">
    <?php foreach ($groups as $name => $columns): ?>
        <?php if ($name === 'Goalkeeping' && !(int) $player['is_goalkeeper']) continue; ?>
        <section>
            <h2><?= View::e($name) ?></h2>
            <table class="data-table stat-table">
                <thead>
                <tr><th>Statistic</th><th class="num">Total</th><th class="num">Per 90</th><th>Source</th></tr>
                </thead>
                <tbody>
                <?php foreach ($columns as $c):
                    $total = PlayerTable::format($player[$c['key']] ?? null, $c, false, $player['minutes']);
                    $per90 = $c['per90'] ? PlayerTable::format($player[$c['key']] ?? null, $c, true, $player['minutes']) : null;
                ?>
                    <tr>
                        <td><?= View::e($c['label']) ?></td>
                        <td class="num<?= $total === null ? ' na' : '' ?>"><?= $total ?? '–' ?></td>
                        <td class="num<?= $per90 === null ? ' na' : '' ?>"><?= $per90 ?? ($c['per90'] ? '–' : '') ?></td>
                        <td class="source"><?= View::e($c['source']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php endforeach; ?>
</div>

<section>
    <h2>Top-scorer lists (football-data.org)</h2>
    <?php if ($fdRows === []): ?>
        <p class="note">
            Not linked to any football-data.org top-scorer list. Either the player isn't in the top
            scorers of the Champions League, World Cup or a league, or the names didn't match.
            Champions League and World Cup numbers are therefore unknown, not zero.
        </p>
    <?php else: ?>
        <table class="data-table stat-table">
            <thead>
            <tr><th>Competition</th><th>Team</th><th class="num">Apps</th><th class="num">Goals</th><th class="num">Assists</th><th class="num">Pens</th><th>Linked by</th></tr>
            </thead>
            <tbody>
            <?php foreach ($fdRows as $r): ?>
                <tr>
                    <td><?= View::e(COMPETITIONS[$r['code']] ?? $r['competition']) ?>
                        <span class="muted"><?= View::e(View::season(['startDate' => $r['start_date'], 'endDate' => $r['end_date']])) ?></span></td>
                    <td><?= View::e($r['team'] ?? '–') ?></td>
                    <?php foreach (['played_matches', 'goals', 'assists', 'penalties'] as $k): ?>
                        <td class="num<?= $r[$k] === null ? ' na' : '' ?>"><?= $r[$k] === null ? '–' : (int) $r[$k] ?></td>
                    <?php endforeach; ?>
                    <td class="source"><?= View::e($r['method']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="note">League rows here come from a different source than the tables above, so small differences are possible.</p>
    <?php endif; ?>
</section>
