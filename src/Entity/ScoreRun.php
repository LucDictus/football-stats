<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ScoreRunRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One calculation of a ranking: which model (with its full definition), on which season and
 * which import batches. Runs are kept, so every published ranking can be explained later.
 */
#[ORM\Entity(repositoryClass: ScoreRunRepository::class)]
#[ORM\Index(columns: ['model_id', 'season_id'])]
class ScoreRun
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private int $rankedCount = 0;

    #[ORM\Column]
    private int $unrankedCount = 0;

    /**
     * @param array<string, mixed> $definition the model as it was when the run was made
     * @param list<int>            $batchIds   import batches the statistics came from
     */
    public function __construct(
        #[ORM\Column(length: 50)]
        private string $modelId,
        #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
        private array $definition,
        #[ORM\Column(length: 64)]
        private string $definitionHash,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private Season $season,
        #[ORM\Column(type: Types::JSON)]
        private array $batchIds,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getModelId(): string
    {
        return $this->modelId;
    }

    /** @return array<string, mixed> */
    public function getDefinition(): array
    {
        return $this->definition;
    }

    public function getDefinitionHash(): string
    {
        return $this->definitionHash;
    }

    public function getSeason(): Season
    {
        return $this->season;
    }

    /** @return list<int> */
    public function getBatchIds(): array
    {
        return $this->batchIds;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getRankedCount(): int
    {
        return $this->rankedCount;
    }

    public function getUnrankedCount(): int
    {
        return $this->unrankedCount;
    }

    public function setCounts(int $ranked, int $unranked): void
    {
        $this->rankedCount = $ranked;
        $this->unrankedCount = $unranked;
    }
}
