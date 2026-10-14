<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SourceRecordRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One record exactly as the source delivered it (a CSV row, an API result object).
 * Nothing is renamed, converted or dropped here; interpretation happens downstream.
 */
#[ORM\Entity(repositoryClass: SourceRecordRepository::class)]
#[ORM\UniqueConstraint(columns: ['batch_id', 'external_id'])]
class SourceRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?string $id = null;

    /** @param array<string, mixed> $payload */
    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private ImportBatch $batch,
        #[ORM\Column(length: 255)]
        private string $externalId,
        #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
        private array $payload,
    ) {
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getBatch(): ImportBatch
    {
        return $this->batch;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }
}
