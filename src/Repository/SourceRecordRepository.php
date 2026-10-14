<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ImportBatch;
use App\Entity\SourceRecord;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SourceRecord> */
class SourceRecordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SourceRecord::class);
    }

    /**
     * Raw payloads of a batch, keyed by external id. Read through DBAL: hydrating thousands
     * of entities only to read their JSON would be wasted work.
     *
     * @return array<string, array<string, mixed>>
     */
    public function payloads(ImportBatch $batch): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllKeyValue(
            'SELECT external_id, payload FROM source_record WHERE batch_id = ? ORDER BY id',
            [$batch->getId()]
        );

        return array_map(static fn (string $json): array => json_decode($json, true, flags: JSON_THROW_ON_ERROR), $rows);
    }

    /** @param iterable<string, array<string, mixed>> $payloads external id => payload */
    public function insertAll(ImportBatch $batch, iterable $payloads): int
    {
        $connection = $this->getEntityManager()->getConnection();
        $count = 0;
        foreach ($payloads as $externalId => $payload) {
            $connection->insert('source_record', [
                'batch_id' => $batch->getId(),
                'external_id' => (string) $externalId,
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            ]);
            ++$count;
        }

        return $count;
    }
}
