<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Playing time for one club in one domestic league (a transfer gives two stints). From FBref. */
#[ORM\Entity]
class PlayerSeasonStint
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'stints')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private PlayerSeason $playerSeason,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private Team $team,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private Competition $competition,
        #[ORM\Column(nullable: true)]
        private ?int $appearances,
        #[ORM\Column(nullable: true)]
        private ?int $starts,
        #[ORM\Column(nullable: true)]
        private ?int $minutes,
    ) {
    }

    public function getPlayerSeason(): PlayerSeason
    {
        return $this->playerSeason;
    }

    public function getTeam(): Team
    {
        return $this->team;
    }

    public function getCompetition(): Competition
    {
        return $this->competition;
    }

    public function getAppearances(): ?int
    {
        return $this->appearances;
    }

    public function getStarts(): ?int
    {
        return $this->starts;
    }

    public function getMinutes(): ?int
    {
        return $this->minutes;
    }
}
