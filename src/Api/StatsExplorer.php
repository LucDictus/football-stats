<?php

declare(strict_types=1);

namespace App\Api;

use App\Entity\PlayerCompetitionStats;
use App\Entity\ScoreRun;
use App\Entity\Season;
use App\Entity\StatsScope;
use App\Football\Data\FieldCatalog;
use App\Football\Data\RoleCatalog;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Any two statistics against each other, for every league player of the season: the data
 * behind the stat explorer (scatter chart and table). Values follow the same trust rules as
 * the scoring engine: per 90 on the supplying provider's own minutes (PlayerCompetitionStats::per90),
 * season totals only when the provider covers the whole season, rates when the provider is
 * usable. Unknown is never zero: a player without a value on an axis is left out and counted.
 */
final readonly class StatsExplorer
{
    /** Axes from the score run rather than the data. */
    public const SCORE_AXES = [
        'score' => ['label' => 'Final score', 'note' => 'The model\'s final score'],
        'positional' => ['label' => 'Positional excellence', 'note' => '50 = the role\'s average, 10 points = one standard deviation'],
        'contribution' => ['label' => 'Contribution (score)', 'note' => 'Goals above replacement, on the points scale'],
        'goals_above_replacement' => ['label' => 'Goals above replacement', 'note' => 'Goals added above a replacement-level player of the role'],
    ];
    public const PER_90 = '90';
    public const PER_TOTAL = 'total';

    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * The statistics an axis can show.
     *
     * @return list<array{key: string, label: string, category: string, per90: bool, goalkeeper: bool, note: ?string}>
     */
    public static function axes(bool $withScores): array
    {
        $axes = [];
        if ($withScores) {
            foreach (self::SCORE_AXES as $key => $axis) {
                $axes[] = ['key' => $key, 'label' => $axis['label'], 'category' => 'Score', 'per90' => false, 'goalkeeper' => false, 'note' => $axis['note']];
            }
        }
        foreach (FieldCatalog::all() as $field) {
            $axes[] = ['key' => $field->key, 'label' => $field->label, 'category' => $field->category, 'per90' => $field->per90, 'goalkeeper' => $field->goalkeeper, 'note' => $field->note];
        }

        return $axes;
    }

    public static function isAxis(string $key, bool $withScores): bool
    {
        return ($withScores && isset(self::SCORE_AXES[$key])) || isset(FieldCatalog::all()[$key]);
    }

    /**
     * @return array{players: list<array{id: int, name: string, team: ?string, role: ?string, line: ?string, position: ?string, minutes: float, rank: ?int, x: float, y: float}>, unknown: array<string, int>}
     */
    public function rows(Season $season, ?ScoreRun $run, string $x, string $y, string $per, float $minMinutes): array
    {
        /** @var list<PlayerCompetitionStats> $stats */
        $stats = $this->em->createQueryBuilder()
            ->select('s', 'ps', 'p', 't')
            ->from(PlayerCompetitionStats::class, 's')
            ->join('s.playerSeason', 'ps')
            ->join('ps.player', 'p')
            ->leftJoin('ps.mainTeam', 't')
            ->where('ps.season = :season AND s.scope = :scope AND s.minutes >= :minutes')
            ->setParameter('season', $season)
            ->setParameter('scope', StatsScope::League)
            ->setParameter('minutes', $minMinutes)
            ->getQuery()
            ->getResult();

        $scores = $run !== null ? $this->scores($run) : [];
        $players = [];
        $unknown = [];
        foreach ($stats as $s) {
            $playerSeason = $s->getPlayerSeason();
            $id = (int) $playerSeason->getPlayer()->getId();
            $vx = $this->value($s, $scores[$id] ?? null, $x, $per);
            $vy = $this->value($s, $scores[$id] ?? null, $y, $per);
            $role = $playerSeason->getRole();
            if ($vx === null || $vy === null) {
                $unknown[$role ?? ''] = ($unknown[$role ?? ''] ?? 0) + 1;
                continue;
            }
            $players[] = [
                'id' => $id,
                'name' => $playerSeason->getPlayer()->getName(),
                'team' => $playerSeason->getMainTeam()?->getName(),
                'role' => $role,
                'line' => $role !== null ? RoleCatalog::line($role) : null,
                'position' => $playerSeason->getPositionGroup(),
                'minutes' => $s->getMinutes(),
                'rank' => $scores[$id]['rank'] ?? null,
                'x' => $vx,
                'y' => $vy,
            ];
        }
        usort($players, static fn (array $a, array $b) => [$b['y'], $b['x'], $a['id']] <=> [$a['y'], $a['x'], $b['id']]);

        return ['players' => $players, 'unknown' => $unknown];
    }

    /** @param array{rank: ?int, score: ?float, positional: ?float, contribution: ?float, goals_above_replacement: ?float}|null $score */
    private function value(PlayerCompetitionStats $s, ?array $score, string $key, string $per): ?float
    {
        if (isset(self::SCORE_AXES[$key])) {
            return $score[$key] ?? null;
        }
        if (!FieldCatalog::get($key)->per90) {
            // Minutes, percentages, ratings: as they are, from a usable provider.
            return $s->fieldStatus($key)->usable() ? $s->value($key) : null;
        }

        return $per === self::PER_TOTAL ? ($s->trusted($key) ? $s->value($key) : null) : $s->per90($key);
    }

    /** @return array<int, array{rank: ?int, score: ?float, positional: ?float, contribution: ?float, goals_above_replacement: ?float}> */
    private function scores(ScoreRun $run): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            "SELECT player_id, rank, score, COALESCE(positional, score) AS positional,
                    (impact->>'score')::float AS contribution, (impact->>'above')::float AS above
             FROM player_score WHERE run_id = :run",
            ['run' => $run->getId()],
        );
        $scores = [];
        foreach ($rows as $r) {
            $ranked = $r['rank'] !== null;
            $scores[(int) $r['player_id']] = [
                'rank' => $ranked ? (int) $r['rank'] : null,
                // Scores of unranked players rest on too little data to compare.
                'score' => $ranked && $r['score'] !== null ? (float) $r['score'] : null,
                'positional' => $ranked && $r['positional'] !== null ? (float) $r['positional'] : null,
                'contribution' => $ranked && $r['contribution'] !== null ? (float) $r['contribution'] : null,
                'goals_above_replacement' => $ranked && $r['above'] !== null ? (float) $r['above'] : null,
            ];
        }

        return $scores;
    }
}
