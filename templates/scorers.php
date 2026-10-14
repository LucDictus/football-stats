<?php

declare(strict_types=1);

use App\View;

/** @var array $data */

$scorers = $data['scorers'] ?? [];
?>
<?php if ($scorers === []): ?>
    <p class="empty">No scorers yet for this season.</p>
<?php else: ?>
    <div class="table-scroll">
        <table class="scorers">
            <thead>
            <tr>
                <th scope="col" class="num">#</th>
                <th scope="col">Player</th>
                <th scope="col" class="team-col">Team</th>
                <th scope="col" class="num" title="Matches played">Apps</th>
                <th scope="col" class="num">Assists</th>
                <th scope="col" class="num" title="Of which penalties">Pens</th>
                <th scope="col" class="num">Goals</th>
            </tr>
            </thead>
            <tbody>
            <?php
            $rank = 0;
            $previousGoals = null;
            foreach ($scorers as $i => $s):
                // Shared rank for equal goal totals.
                if ($s['goals'] !== $previousGoals) {
                    $rank = $i + 1;
                    $previousGoals = $s['goals'];
                }
            ?>
                <tr>
                    <td class="num"><?= $rank ?></td>
                    <td>
                        <?= View::e($s['player']['name']) ?>
                        <?php if (!empty($s['player']['nationality'])): ?>
                            <span class="muted"><?= View::e($s['player']['nationality']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="team-col"><?= View::crest($s['team']) ?> <?= View::e(View::teamName($s['team'])) ?></td>
                    <td class="num"><?= View::e($s['playedMatches'] ?? '–') ?></td>
                    <td class="num"><?= View::e($s['assists'] ?? '–') ?></td>
                    <td class="num"><?= View::e($s['penalties'] ?? '–') ?></td>
                    <td class="num points"><?= (int) $s['goals'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
