<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** How a source refers to a team: a SofaScore team id, or the club name used in the Top-5 dataset. */
#[ORM\Entity]
#[ORM\UniqueConstraint(columns: ['source', 'external_id'])]
class TeamIdentifier
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Team $team,
        #[ORM\Column(length: 50)]
        private string $source,
        #[ORM\Column(length: 255)]
        private string $externalId,
    ) {
    }

    public function getTeam(): Team
    {
        return $this->team;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }
}
