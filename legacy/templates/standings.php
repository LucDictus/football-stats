<?php

declare(strict_types=1);

use App\View;

/** @var array $data */

// The API returns TOTAL, HOME and AWAY tables; we only show the totals.
$tables = array_filter($data['standings'] ?? [], static fn (array $s) => ($s['type'] ?? 'TOTAL') === 'TOTAL');
?>
<?php if ($tables === []): ?>
    <p class="empty">No standings for this competition or season. Knockout-only rounds have no table.</p>
<?php endif; ?>

<div class="tables">
    <?php foreach ($tables as $standing): ?>
        <section class="standing">
            <h2><?= View::e(View::label($standing['group'] ?? null) ?: View::label($standing['stage'])) ?></h2>
            <div class="table-scroll">
                <table>
                    <thead>
                    <tr>
                        <th scope="col" class="num">#</th>
                        <th scope="col" class="team-col">Team</th>
                        <th scope="col" class="num" title="Played">P</th>
                        <th scope="col" class="num" title="Won">W</th>
                        <th scope="col" class="num" title="Drawn">D</th>
                        <th scope="col" class="num" title="Lost">L</th>
                        <th scope="col" class="num" title="Goal difference">GD</th>
                        <th scope="col" class="num" title="Points">Pts</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($standing['table'] as $row): ?>
                        <tr>
                            <td class="num"><?= (int) $row['position'] ?></td>
                            <td class="team-col">
                                <?= View::crest($row['team']) ?>
                                <?= View::e(View::teamName($row['team'])) ?>
                            </td>
                            <td class="num"><?= (int) $row['playedGames'] ?></td>
                            <td class="num"><?= (int) $row['won'] ?></td>
                            <td class="num"><?= (int) $row['draw'] ?></td>
                            <td class="num"><?= (int) $row['lost'] ?></td>
                            <td class="num"><?= sprintf('%+d', (int) $row['goalDifference']) ?></td>
                            <td class="num points"><?= (int) $row['points'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endforeach; ?>
</div>
