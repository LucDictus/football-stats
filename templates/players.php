<?php

declare(strict_types=1);

use App\PlayerRepository;
use App\View;

/**
 * @var array  $ranking
 * @var array  $filters
 * @var string $filter
 * @var string $sort
 * @var string $search
 */

$showBreakdown = in_array($filter, ['all', 'leagues', 'cups'], true);
?>
<nav class="filter" aria-label="Competitions">
    <?php foreach ($filters as $value => $label): ?>
        <a href="<?= View::e(View::url(['filter' => $value === 'all' ? null : $value])) ?>"
           <?= $value === $filter ? 'aria-current="page"' : '' ?>><?= View::e($label) ?></a>
    <?php endforeach; ?>
</nav>

<form class="player-search" method="get">
    <?php if ($filter !== 'all'): ?><input type="hidden" name="filter" value="<?= View::e($filter) ?>"><?php endif; ?>
    <label>Player <input type="search" name="q" value="<?= View::e($search) ?>" placeholder="Name"></label>
    <label>Sort by
        <select name="sort">
            <?php foreach (PlayerRepository::SORTS as $value => $label): ?>
                <option value="<?= View::e($value) ?>" <?= $value === $sort ? 'selected' : '' ?>><?= View::e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <button type="submit">Show</button>
</form>

<?php if ($ranking === []): ?>
    <p class="empty">No players found. Clear the search or pick another competition.</p>
<?php else: ?>
    <div class="table-scroll">
        <table class="players">
            <thead>
            <tr>
                <th scope="col" class="num">#</th>
                <th scope="col">Player</th>
                <th scope="col">Team</th>
                <th scope="col" class="num" title="Matches played">Apps</th>
                <th scope="col" class="num">Goals</th>
                <th scope="col" class="num">Assists</th>
                <th scope="col" class="num" title="Goals + assists">G+A</th>
                <th scope="col" class="num" title="Penalty goals">Pens</th>
                <?php if ($showBreakdown): ?><th scope="col">Goals per competition</th><?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($ranking as $i => $p): ?>
                <tr>
                    <td class="num"><?= $i + 1 ?></td>
                    <td>
                        <a href="players.php?id=<?= (int) $p['id'] ?>"><?= View::e($p['name']) ?></a>
                        <?php if (!empty($p['nationality'])): ?><span class="muted"><?= View::e($p['nationality']) ?></span><?php endif; ?>
                    </td>
                    <td><?= View::e(str_replace(',', ', ', (string) $p['teams'])) ?></td>
                    <td class="num"><?= (int) $p['apps'] ?></td>
                    <td class="num<?= $sort === 'goals' ? ' points' : '' ?>"><?= (int) $p['goals'] ?></td>
                    <td class="num<?= $sort === 'assists' ? ' points' : '' ?>"><?= (int) $p['assists'] ?></td>
                    <td class="num<?= $sort === 'contributions' ? ' points' : '' ?>"><?= (int) $p['contributions'] ?></td>
                    <td class="num"><?= (int) $p['penalties'] ?></td>
                    <?php if ($showBreakdown): ?>
                        <td class="chips">
                            <?php foreach ($p['breakdown'] as $code => $goals): ?>
                                <span class="chip" title="<?= View::e(COMPETITIONS[$code] ?? $code) ?>"><?= View::e($code) ?> <b><?= (int) $goals ?></b></span>
                            <?php endforeach; ?>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
