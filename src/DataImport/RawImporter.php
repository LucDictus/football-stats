<?php

declare(strict_types=1);

namespace App\DataImport;

use App\Entity\ImportBatch;
use App\Repository\SourceRecordRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Stores one dataset as a new ImportBatch with its raw records, in one transaction:
 * either the whole batch is stored or nothing is.
 */
final readonly class RawImporter
{
    public function __construct(
        private EntityManagerInterface $em,
        private SourceRecordRepository $records,
    ) {
    }

    /**
     * @param iterable<string, array<string, mixed>> $payloads external id => raw record
     * @param array<string, mixed>                   $notes
     */
    public function import(ImportBatch $batch, iterable $payloads, int $rejected = 0, array $notes = []): ImportBatch
    {
        $this->em->wrapInTransaction(function () use ($batch, $payloads, $rejected, $notes): void {
            $this->em->persist($batch);
            $this->em->flush();
            $batch->complete($this->records->insertAll($batch, $payloads), $rejected, $notes);
            $this->em->flush();
        });

        return $batch;
    }
}
