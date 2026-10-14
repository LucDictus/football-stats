<?php

declare(strict_types=1);

namespace App\DataImport;

use App\Entity\ImportBatch;
use App\Repository\SourceRecordRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Stores one dataset as a new ImportBatch with its raw records, in one transaction:
 * either the whole batch is stored or nothing is. An empty dataset is refused: it would
 * become the "latest" batch and silently empty every season built from it.
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
            $count = $this->records->insertAll($batch, $payloads);
            if ($count === 0) {
                throw new EmptyImportException(sprintf('%s %s %s has no records; nothing was imported.', $batch->getSource(), $batch->getDataset(), $batch->getSeason()));
            }
            $batch->complete($count, $rejected, $notes);
            $this->em->flush();
        });

        return $batch;
    }
}
