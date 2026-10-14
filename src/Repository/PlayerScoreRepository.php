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
}
