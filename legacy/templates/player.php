<?php

declare(strict_types=1);

use App\View;

/**
 * @var array $player
 * @var array $rows          one row per competition
 * @var array $withoutStats  competition codes where the player's team played but the player has no stats
 */

$sum = static fn (array $rows, string $key): int => array_sum(array_map(static fn ($r) => (int) ($r[$key] ?? 0), $rows));

$groups = [
    'Leagues' => array_filter($rows, static fn ($r) => $r['type'] === 'LEAGUE'),
    'Cups and tournaments' => array_filter($rows, static fn ($r) => $r['type'] !== 'LEAGUE'),
];

$age = null;
if (!empty($player['date_of_birth'])) {
    $age = (new DateTimeImmutable($player['date_of_birth']))->diff(new DateTimeImmutable())->y;
}
?>
<p><a href="players.php">All players</a></p>

<dl class="player-facts">
    <?php if (!empty($player['nationality'])): ?><div><dt>Nationality</dt><dd><?= View::e($player['nationality']) ?></dd></div><?php endif; ?>
    <?php if (!empty($player['position'])): ?><div><dt>Position</dt><dd><?= View::e($player['position']) ?></dd></div><?php endif; ?>
    <?php if ($age !== null): ?><div><dt>Age</dt><dd><?= $age ?> <span class="muted">(<?= View::e($player['date_of_birth']) ?>)</span></dd></div><?php endif; ?>
</dl>

<div class="table-scroll">
    <table class="player-breakdown">
        <thead>
        <tr>
            <th scope="col">Competition</th>
            <th scope="col">Team</th>
            <th scope="col" class="num" title="Matches played">Apps</th>
            <th scope="col" class="num">Goals</th>
            <th scope="col" class="num">Assists</th>
            <th scope="col" class="num" title="Goals + assists">G+A</th>
            <th scope="col" class="num" title="Penalty goals">Pens</th>
        </tr>
        </thead>
        <?php foreach ($groups as $label => $groupRows): ?>
            <?php if ($groupRows === []) continue; ?>
            <tbody>
            <tr class="group-row"><th scope="rowgroup" colspan="7"><?= View::e($label) ?></th></tr>
            <?php foreach ($groupRows as $r): ?>
                <tr>
                    <td><?= View::e(COMPETITIONS[$r['code']] ?? $r['competition']) ?> <span class="muted"><?= View::e(View::season(['startDate' => $r['start_date'], 'endDate' => $r['end_date']])) ?></span></td>
                    <td><?= View::crest(['crest' => $r['crest']]) ?> <?= View::e($r['team'] ?? '–') ?></td>
                    <td class="num"><?= View::e($r['played_matches'] ?? '–') ?></td>
                    <td class="num"><?= View::e($r['goals'] ?? '–') ?></td>
                    <td class="num"><?= View::e($r['assists'] ?? '–') ?></td>
                    <td class="num"><?= (int) $r['goals'] + (int) $r['assists'] ?></td>
                    <td class="num"><?= View::e($r['penalties'] ?? '–') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (count($groupRows) > 1): ?>
                <tr class="subtotal">
                    <th scope="row" colspan="2"><?= View::e($label) ?> total</th>
                    <td class="num"><?= $sum($groupRows, 'played_matches') ?></td>
                    <td class="num"><?= $sum($groupRows, 'goals') ?></td>
                    <td class="num"><?= $sum($groupRows, 'assists') ?></td>
                    <td class="num"><?= $sum($groupRows, 'goals') + $sum($groupRows, 'assists') ?></td>
                    <td class="num"><?= $sum($groupRows, 'penalties') ?></td>
                </tr>
            <?php endif; ?>
            </tbody>
        <?php endforeach; ?>
        <tfoot>
        <tr class="total">
            <th scope="row" colspan="2">Total</th>
            <td class="num"><?= $sum($rows, 'played_matches') ?></td>
            <td class="num"><?= $sum($rows, 'goals') ?></td>
            <td class="num"><?= $sum($rows, 'assists') ?></td>
            <td class="num"><?= $sum($rows, 'goals') + $sum($rows, 'assists') ?></td>
            <td class="num"><?= $sum($rows, 'penalties') ?></td>
        </tr>
        </tfoot>
    </table>
</div>

<?php if ($withoutStats !== []): ?>
    <p class="note">
        Their team also played in
        <?= View::e(implode(', ', array_map(static fn ($c) => COMPETITIONS[$c] ?? $c, $withoutStats))) ?>,
        but the player isn't in that top-scorer list, so those matches aren't counted here.
    </p>
<?php endif; ?>
