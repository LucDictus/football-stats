<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * How a source refers to a player, and how that link was made. One player can have
 * identifiers from several sources; each (source, external id) points to one player.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(columns: ['source', 'external_id'])]
class PlayerIdentifier
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Player $player,
        #[ORM\Column(length: 50)]
        private string $source,
        #[ORM\Column(length: 255)]
        private string $externalId,
        /** e.g. "source id", "sofascore id in league data", "manual", "name + club" */
        #[ORM\Column(length: 50)]
        private string $method,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getPlayer(): Player
    {
        return $this->player;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    public function getMethod(): string
    {
        return $this->method;
    }
}
