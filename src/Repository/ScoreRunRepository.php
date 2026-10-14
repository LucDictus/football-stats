<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ScoreRun;
use App\Entity\Season;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ScoreRun> */
class ScoreRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ScoreRun::class);
    }

    public function latest(string $modelId, Season $season): ?ScoreRun
    {
        return $this->findOneBy(['modelId' => $modelId, 'season' => $season], ['id' => 'DESC']);
    }
}
