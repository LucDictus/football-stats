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
    ) {
    }
}
