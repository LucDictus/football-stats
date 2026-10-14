<?php

declare(strict_types=1);

namespace App\DataQuality;

/** How far a provider's numbers for one player can be trusted. */
enum OriginStatus: string
{
    /** Covers the player's whole season and agrees with FBref minutes (and goals). Use totals and per-90s. */
    case Ok = 'ok';
    /** Covers only part of the season (a transfer between leagues). Per-90 on the provider's own minutes only. */
    case Partial = 'partial';
    /** Disagrees with FBref beyond tolerance: probably another player's record. Treat as missing. */
    case Suspect = 'suspect';
    /** No record in this provider. Missing, never zero. */
    case Missing = 'missing';

    public function usable(): bool
    {
        return $this === self::Ok || $this === self::Partial;
    }
}
