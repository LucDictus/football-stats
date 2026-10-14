<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlayerScoreRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A player's result in one score run, with the full breakdown that explains it.
 * Linked to Player (stable id), not to PlayerSeason (rebuilt on every season build).
 */
#[ORM\Entity(repositoryClass: PlayerScoreRepository::class)]
#[ORM\Index(columns: ['run_id', 'rank'])]
#[ORM\UniqueConstraint(columns: ['run_id', 'player_id'])]
class PlayerScore
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * @param array<string, array{score: ?float, weight: float, coverage: float, metrics: array<string, array{value: ?float, percentile: ?float, weight: float}>}> $components
     */
    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private ScoreRun $run,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Player $player,
        /** Null: listed, but too little of the model could be measured to rank him. */
        #[ORM\Column(nullable: true)]
        private ?int $rank,
        #[ORM\Column(nullable: true)]
        private ?float $score,
        #[ORM\Column]
        private float $confidence,
        #[ORM\Column(length: 2, nullable: true)]
        private ?string $positionGroup,
        /** Club at the time of the run (a snapshot, so old runs stay readable). */
        #[ORM\Column(length: 100, nullable: true)]
        private ?string $team,
        #[ORM\Column]
        private float $minutes,
        #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
        private array $components,
        /** Weighted mean of the components (before normalising within the role). */
        #[ORM\Column(nullable: true)]
        private ?float $composite = null,
    ) {
    }

    public function getComposite(): ?float
    {
        return $this->composite;
    }

    public function getRun(): ScoreRun
    {
        return $this->run;
    }

    public function getPlayer(): Player
    {
        return $this->player;
    }

    public function getRank(): ?int
    {
        return $this->rank;
    }

    public function getScore(): ?float
    {
        return $this->score;
    }

    public function getConfidence(): float
    {
        return $this->confidence;
    }

    public function getPositionGroup(): ?string
    {
        return $this->positionGroup;
    }

    public function getTeam(): ?string
    {
        return $this->team;
    }

    public function getMinutes(): float
    {
        return $this->minutes;
    }

    /** @return array<string, array{score: ?float, weight: float, coverage: float, metrics: array<string, array{value: ?float, percentile: ?float, weight: float}>}> */
    public function getComponents(): array
    {
        return $this->components;
    }
}
