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
     * Ranked players first (by rank), then the unranked ones (by score).
     *
     * @return list<PlayerScore>
     */
    public function forRun(ScoreRun $run, ?string $positionGroup = null, int $limit = 100, int $offset = 0, bool $includeUnranked = false): array
    {
        $qb = $this->createQueryBuilder('s')
            ->addSelect('p')
            ->join('s.player', 'p')
            ->where('s.run = :run')
            ->setParameter('run', $run)
            ->orderBy('s.rank', 'ASC')
            ->addOrderBy('s.score', 'DESC')
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

    public function forPlayer(ScoreRun $run, Player $player): ?PlayerScore
    {
        return $this->findOneBy(['run' => $run, 'player' => $player]);
    }
}
