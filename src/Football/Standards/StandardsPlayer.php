<?php

declare(strict_types=1);

namespace App\Football\Standards;

use App\Football\Scoring\ScoringCandidate;

/** One player season in the data the standards are derived from. */
final readonly class StandardsPlayer
{
    public function __construct(
        /** Identifies the player across seasons: name and birth year (the Top-5 dataset's key). */
        public string $key,
        public string $season,
        /** Role (RoleCatalog) as the player's position group in the candidate. */
        public ScoringCandidate $candidate,
        /** His main team's goal difference per match. */
        public ?float $goalDifference,
        /** His main team's estimated possession share. */
        public ?float $possession,
    ) {
    }

    public function role(): ?string
    {
        return $this->candidate->role;
    }
}
