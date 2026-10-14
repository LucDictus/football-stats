<?php

declare(strict_types=1);

use App\PlayerTable;
use App\View;

/**
 * @var PlayerTable $table
 * @var array       $filters
 * @var array       $result  ['rows' => ..., 'total' => ...]
 */

$groups = $table->groups();
$groupIndex = array_flip(array_keys($groups));
$from = ($filters['page'] - 1) * PlayerTable::PER_PAGE;
$pages = (int) ceil($result['total'] / PlayerTable::PER_PAGE);

/** Header link: clicking the sorted column flips the direction. */
$sortUrl = static function (string $key) use ($filters): string {
    $dir = $filters['sort'] === $key && $filters['dir'] === 'desc' ? 'asc' : 'desc';
    if ($filters['sort'] !== $key) {
        $dir = $key === 'player' ? 'asc' : 'desc';
    }

    return View::url(['sort' => $key, 'dir' => $dir, 'page' => null]);
};
$arrow = static fn (string $key): string => $filters['sort'] === $key ? ($filters['dir'] === 'asc' ? ' ▲' : ' ▼') : '';
?>
<form class="table-filters" method="get">
    <label>League
        <select name="league">
            <option value="all">All five leagues</option>
            <?php foreach (PlayerTable::LEAGUES as $code => $name): ?>
                <option value="<?= View::e($code) ?>" <?= $filters['league'] === $code ? 'selected' : '' ?>><?= View::e($name) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Position
        <select name="position">
            <?php foreach (PlayerTable::POSITIONS as $code => $name): ?>
                <option value="<?= View::e($code) ?>" <?= $filters['position'] === $code ? 'selected' : '' ?>><?= View::e($name) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Min. minutes
        <input type="number" name="min" min="0" step="90" value="<?= (int) $filters['min_minutes'] ?>">
    </label>
    <label>Player
        <input type="search" name="q" value="<?= View::e($filters['q']) ?>" placeholder="Name">
    </label>
    <label class="check"><input type="checkbox" name="per90" value="1" <?= $filters['per90'] ? 'checked' : '' ?>> Per 90 minutes</label>
    <input type="hidden" name="sort" value="<?= View::e($filters['sort']) ?>">
    <input type="hidden" name="dir" value="<?= View::e($filters['dir']) ?>">
    <button type="submit">Apply</button>
</form>

<div class="column-toggles" data-table="player-table">
    <span>Columns:</span>
    <?php foreach ($groups as $name => $columns): ?>
        <label><input type="checkbox" value="g<?= $groupIndex[$name] ?>" checked> <?= View::e($name) ?></label>
    <?php endforeach; ?>
</div>

<p class="table-summary">
    <?php if ($result['total'] === 0): ?>
        No players match these filters.
    <?php else: ?>
        <?= number_format($from + 1) ?>–<?= number_format(min($from + PlayerTable::PER_PAGE, $result['total'])) ?>
        of <?= number_format($result['total']) ?> players.
        <?= $filters['per90'] ? 'Values per 90 minutes (playing time and percentages stay totals).' : 'Season totals.' ?>
        Hover a column header for its full name and source.
    <?php endif; ?>
</p>

<?php if ($result['total'] > 0): ?>
<div class="wide-table-wrap">
    <table class="data-table" id="player-table">
        <thead>
        <tr class="group-head">
            <th colspan="2"></th>
            <?php foreach ($groups as $name => $columns): ?>
                <th class="g<?= $groupIndex[$name] ?>" colspan="<?= count($columns) ?>"><?= View::e($name) ?></th>
            <?php endforeach; ?>
        </tr>
        <tr class="col-head">
            <th class="num">#</th>
            <th class="player-col"><a href="<?= View::e($sortUrl('player')) ?>">Player<?= $arrow('player') ?></a></th>
            <?php foreach ($groups as $name => $columns): ?>
                <?php foreach ($columns as $i => $c): ?>
                    <th class="num g<?= $groupIndex[$name] ?><?= $i === 0 ? ' group-start' : '' ?><?= $filters['sort'] === $c['key'] ? ' sorted' : '' ?>"
                        title="<?= View::e($c['label'] . ' – ' . $c['source']) ?>">
                        <a href="<?= View::e($sortUrl($c['key'])) ?>"><?= View::e($c['short']) ?><?= $arrow($c['key']) ?></a>
                    </th>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($result['rows'] as $n => $row): ?>
            <tr>
                <td class="num muted-num"><?= $from + $n + 1 ?></td>
                <td class="player-col">
                    <a href="players.php?id=<?= (int) $row['id'] ?>"><?= View::e($row['player']) ?></a>
                    <span class="player-meta"><?= View::e($row['teams']) ?><?= $row['pos'] ? ', ' . View::e(str_replace(',', '/', $row['pos'])) : '' ?><?= $row['age'] ? ', ' . (int) $row['age'] : '' ?></span>
                </td>
                <?php foreach ($groups as $name => $columns): ?>
                    <?php foreach ($columns as $i => $c):
                        $value = PlayerTable::format($row[$c['key']] ?? null, $c, $filters['per90'], $row['minutes']);
                        $classes = 'num g' . $groupIndex[$name] . ($i === 0 ? ' group-start' : '') . ($filters['sort'] === $c['key'] ? ' sorted' : '');
                    ?>
                        <?php if ($name === 'Goalkeeping' && !(int) $row['is_goalkeeper']): ?>
                            <td class="<?= $classes ?> na" title="Goalkeepers only"></td>
                        <?php elseif ($value === null): ?>
                            <td class="<?= $classes ?> na" title="No value in the source">–</td>
                        <?php else: ?>
                            <td class="<?= $classes ?>"><?= $value ?></td>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($pages > 1): ?>
    <nav class="pager" aria-label="Pages">
        <?php if ($filters['page'] > 1): ?>
            <a href="<?= View::e(View::url(['page' => $filters['page'] - 1])) ?>">← Previous</a>
        <?php endif; ?>
        <span>Page <?= $filters['page'] ?> of <?= $pages ?></span>
        <?php if ($filters['page'] < $pages): ?>
            <a href="<?= View::e(View::url(['page' => $filters['page'] + 1])) ?>">Next →</a>
        <?php endif; ?>
    </nav>
<?php endif; ?>
<?php endif; ?>

<script>
// Show/hide column groups; the choice is remembered in this browser.
(() => {
    const box = document.querySelector('.column-toggles');
    const table = document.getElementById(box.dataset.table);
    if (!table) return;
    const key = 'hiddenColumnGroups';
    let hidden = [];
    try { hidden = JSON.parse(localStorage.getItem(key) || '[]'); } catch (e) {}

    const apply = () => {
        box.querySelectorAll('input').forEach((input) => {
            input.checked = !hidden.includes(input.value);
            table.classList.toggle('hide-' + input.value, !input.checked);
        });
    };
    box.addEventListener('change', (event) => {
        const group = event.target.value;
        hidden = event.target.checked ? hidden.filter((g) => g !== group) : [...hidden, group];
        try { localStorage.setItem(key, JSON.stringify(hidden)); } catch (e) {}
        apply();
    });
    apply();
})();
</script>
