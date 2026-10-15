<?php

declare(strict_types=1);

namespace App\Football\Scoring;

use App\Entity\PlayerCompetitionStats;

/** A player season offered to a model: identity plus the statistics of the model's scope. */
final readonly class ScoringCandidate
{
    public function __construct(
        public int $playerId,
        public string $name,
        public ?string $positionGroup,
        public ?string $team,
        public PlayerCompetitionStats $stats,
        /** Domestic league code of the main team (league averages in the impact model). */
        public ?string $league = null,
        /** Finer role (RoleCatalog), for models that compare players by role. */
        public ?string $role = null,
    ) {
    }

    /** The same player, compared within his finer role instead of his position group. */
    public function byRole(): self
    {
        return new self($this->playerId, $this->name, $this->role, $this->team, $this->stats, $this->league, $this->role);
    }
}
