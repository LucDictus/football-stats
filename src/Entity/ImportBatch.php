<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ImportBatchRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One import run of one dataset from one source: the provenance of every raw record.
 *
 * Batches are never overwritten. The latest batch per (source, dataset, season) is the
 * current one; older batches stay so a ranking can be reproduced after a source changes.
 */
#[ORM\Entity(repositoryClass: ImportBatchRepository::class)]
#[ORM\Index(columns: ['source', 'dataset', 'season'])]
class ImportBatch
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $recordCount = 0;

    #[ORM\Column]
    private int $rejectedCount = 0;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $notes = [];

    #[ORM\Column]
    private \DateTimeImmutable $importedAt;

    public function __construct(
        #[ORM\Column(length: 50)]
        private string $source,
        #[ORM\Column(length: 100)]
        private string $dataset,
        #[ORM\Column(length: 9)]
        private string $season,
        #[ORM\Column(type: Types::TEXT)]
        private string $sourceUrl,
        #[ORM\Column(length: 20)]
        private string $transformVersion,
        /** Competition code(s) the dataset covers, comma-separated. */
        #[ORM\Column(length: 50, nullable: true)]
        private ?string $competitionCode = null,
        #[ORM\Column(length: 100, nullable: true)]
        private ?string $sourceVersion = null,
    ) {
        $this->importedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getDataset(): string
    {
        return $this->dataset;
    }

    public function getSeason(): string
    {
        return $this->season;
    }

    public function getCompetitionCode(): ?string
    {
        return $this->competitionCode;
    }

    public function getSourceUrl(): string
    {
        return $this->sourceUrl;
    }

    public function getSourceVersion(): ?string
    {
        return $this->sourceVersion;
    }

    public function getTransformVersion(): string
    {
        return $this->transformVersion;
    }

    public function getImportedAt(): \DateTimeImmutable
    {
        return $this->importedAt;
    }

    public function getRecordCount(): int
    {
        return $this->recordCount;
    }

    public function getRejectedCount(): int
    {
        return $this->rejectedCount;
    }

    /** @return array<string, mixed> */
    public function getNotes(): array
    {
        return $this->notes;
    }

    /** @param array<string, mixed> $notes */
    public function complete(int $recordCount, int $rejectedCount, array $notes = []): void
    {
        $this->recordCount = $recordCount;
        $this->rejectedCount = $rejectedCount;
        $this->notes = $notes;
    }
}
