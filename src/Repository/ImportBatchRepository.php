<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ImportBatch;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ImportBatch> */
class ImportBatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImportBatch::class);
    }

    /** The current batch: the most recent complete import of this dataset. */
    public function latest(string $source, string $dataset, string $season): ?ImportBatch
    {
        return $this->createQueryBuilder('b')
            ->where('b.source = :source AND b.dataset = :dataset AND b.season = :season AND b.recordCount > 0')
            ->setParameters(['source' => $source, 'dataset' => $dataset, 'season' => $season])
            ->orderBy('b.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
