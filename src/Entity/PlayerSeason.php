<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/** A player in one season: role, clubs, and statistics per competition scope. */
#[ORM\Entity]
#[ORM\UniqueConstraint(columns: ['player_id', 'season_id'])]
class PlayerSeason
{
    public const POSITION_GROUPS = ['GK', 'DF', 'MF', 'FW'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var Collection<int, PlayerSeasonStint> */
    #[ORM\OneToMany(targetEntity: PlayerSeasonStint::class, mappedBy: 'playerSeason', cascade: ['persist'])]
    private Collection $stints;

    /** @var Collection<int, PlayerCompetitionStats> */
    #[ORM\OneToMany(targetEntity: PlayerCompetitionStats::class, mappedBy: 'playerSeason', cascade: ['persist'])]
    private Collection $stats;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private Player $player,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private Season $season,
        /** Positions as listed by the source, most played first: "MF,FW". */
        #[ORM\Column(length: 20, nullable: true)]
        private ?string $positions = null,
        /** Main position group: GK, DF, MF or FW. Null when no source gives a position. */
        #[ORM\Column(length: 2, nullable: true)]
        private ?string $positionGroup = null,
        /** The club the player played most league minutes for (or their CL club). */
        #[ORM\ManyToOne]
        private ?Team $mainTeam = null,
    ) {
        $this->stints = new ArrayCollection();
        $this->stats = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPlayer(): Player
    {
        return $this->player;
    }

    public function getSeason(): Season
    {
        return $this->season;
    }

    public function getPositions(): ?string
    {
        return $this->positions;
    }

    public function getPositionGroup(): ?string
    {
        return $this->positionGroup;
    }

    public function isGoalkeeper(): bool
    {
        return $this->positionGroup === 'GK';
    }

    public function getMainTeam(): ?Team
    {
        return $this->mainTeam;
    }

    /** @return Collection<int, PlayerSeasonStint> */
    public function getStints(): Collection
    {
        return $this->stints;
    }

    public function addStint(PlayerSeasonStint $stint): void
    {
        $this->stints->add($stint);
    }

    public function getStats(StatsScope $scope): ?PlayerCompetitionStats
    {
        foreach ($this->stats as $stats) {
            if ($stats->getScope() === $scope) {
                return $stats;
            }
        }

        return null;
    }

    public function addStats(PlayerCompetitionStats $stats): void
    {
        $this->stats->add($stats);
    }
}
