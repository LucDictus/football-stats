<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Player;
use App\Entity\PlayerScore;
use App\Entity\ScoreRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PlayerScore> */
class PlayerScoreRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlayerScore::class);
    }

    /**
     * The calculator's order: ranked players by rank, then the unranked ones by score (unknown
     * last), minutes and player id. Fully deterministic, so pages never overlap or skip rows.
     *
     * @return list<PlayerScore>
     */
    public function forRun(ScoreRun $run, ?string $positionGroup = null, int $limit = 100, int $offset = 0, bool $includeUnranked = false): array
    {
        $qb = $this->createQueryBuilder('s')
            ->addSelect('p')
            ->addSelect('CASE WHEN s.rank IS NULL THEN 1 ELSE 0 END AS HIDDEN unranked')
            ->addSelect('CASE WHEN s.score IS NULL THEN 1 ELSE 0 END AS HIDDEN unscored')
            ->join('s.player', 'p')
            ->where('s.run = :run')
            ->setParameter('run', $run)
            ->orderBy('unranked', 'ASC')
            ->addOrderBy('s.rank', 'ASC')
            ->addOrderBy('unscored', 'ASC')
            ->addOrderBy('s.score', 'DESC')
            ->addOrderBy('s.minutes', 'DESC')
            ->addOrderBy('p.id', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);
        if ($positionGroup !== null) {
            $qb->andWhere('s.positionGroup = :group')->setParameter('group', $positionGroup);
        }
        if (!$includeUnranked) {
            $qb->andWhere('s.rank IS NOT NULL');
        }

        /** @var list<PlayerScore> */
        return $qb->getQuery()->getResult();
    }

    public function countForRun(ScoreRun $run, ?string $positionGroup = null, bool $includeUnranked = false): int
    {
        $criteria = ['run' => $run];
        if ($positionGroup !== null) {
            $criteria['positionGroup'] = $positionGroup;
        }
        $count = $this->count($criteria);

        return $includeUnranked ? $count : $count - $this->count($criteria + ['rank' => null]);
    }

    public function forPlayer(ScoreRun $run, Player $player): ?PlayerScore
    {
        return $this->findOneBy(['run' => $run, 'player' => $player]);
    }

    /**
     * Every ranked player of a group (null: all groups) on one part of the score: its points and, for metrics and
     * the contribution, the value behind them. Sorted by points, best first.
     *
     * @param string      $part      final | positional | contribution | component | metric
     * @param string|null $component component key (component, metric)
     * @param string|null $metric    metric key (metric)
     *
     * @return list<array{id: int, name: string, team: ?string, position: ?string, rank: ?int, points: float, value: ?float, adjusted: ?float}>
     */
    public function distribution(ScoreRun $run, ?string $group, string $part, ?string $component = null, ?string $metric = null): array
    {
        [$points, $value, $adjusted] = match ($part) {
            'final' => ['s.score', 'NULL', 'NULL'],
            // Models without a contribution: the positional score is the score.
            'positional' => ['COALESCE(s.positional, s.score)', 'NULL', 'NULL'],
            'contribution' => ["(s.impact->>'score')::float", "(s.impact->>'above')::float", 'NULL'],
            'component' => ["(s.components->:component->>'score')::float", 'NULL', 'NULL'],
            'metric' => [
                "(s.components->:component->'metrics'->:metric->>'percentile')::float",
                "(s.components->:component->'metrics'->:metric->>'value')::float",
                "(s.components->:component->'metrics'->:metric->>'adjusted')::float",
            ],
            default => throw new \InvalidArgumentException("Unknown part {$part}"),
        };
        $params = ['run' => $run->getId()] + ($group !== null ? ['group' => $group] : [])
            + ($component !== null && in_array($part, ['component', 'metric'], true) ? ['component' => $component] : [])
            + ($metric !== null && $part === 'metric' ? ['metric' => $metric] : []);

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT * FROM (
                SELECT p.id, p.name, s.team, s.position_group, s.rank, {$points} AS points, {$value} AS value, {$adjusted} AS adjusted
                FROM player_score s JOIN player p ON p.id = s.player_id
                WHERE s.run_id = :run AND s.rank IS NOT NULL" . ($group !== null ? ' AND s.position_group = :group' : '') . '
             ) d WHERE points IS NOT NULL
             ORDER BY points DESC, id',
            $params,
        );

        return array_map(static fn (array $r) => [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'team' => $r['team'] !== null ? (string) $r['team'] : null,
            'position' => $r['position_group'] !== null ? (string) $r['position_group'] : null,
            'rank' => $r['rank'] !== null ? (int) $r['rank'] : null,
            'points' => (float) $r['points'],
            'value' => $r['value'] !== null ? (float) $r['value'] : null,
            'adjusted' => $r['adjusted'] !== null ? (float) $r['adjusted'] : null,
        ], $rows);
    }
}
