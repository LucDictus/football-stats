<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** A club. Sources name clubs differently ("PSG", "Paris Saint-Germain"); TeamIdentifier maps them here. */
#[ORM\Entity]
class Team
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(length: 100)]
        private string $name,
        /** The top-5 league the club plays in; null for clubs we only know from the Champions League. */
        #[ORM\ManyToOne]
        private ?Competition $domesticLeague = null,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDomesticLeague(): ?Competition
    {
        return $this->domesticLeague;
    }
}
