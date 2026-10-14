<?php

declare(strict_types=1);

namespace App;

final class Score
{
    /**
     * Normalises an API score object.
     *
     * fullTime can include shoot-out goals, so for shoot-outs the score is
     * rebuilt from regular + extra time and the penalties are returned separately.
     *
     * @return array{home: ?int, away: ?int, penHome: ?int, penAway: ?int}
     */
    public static function normalize(array $score): array
    {
        $home = $score['fullTime']['home'] ?? null;
        $away = $score['fullTime']['away'] ?? null;
        $penHome = $score['penalties']['home'] ?? null;
        $penAway = $score['penalties']['away'] ?? null;

        if (($score['duration'] ?? '') === 'PENALTY_SHOOTOUT'
            && isset($score['regularTime']['home'], $score['regularTime']['away'])
        ) {
            $home = $score['regularTime']['home'] + ($score['extraTime']['home'] ?? 0);
            $away = $score['regularTime']['away'] + ($score['extraTime']['away'] ?? 0);
        }

        return ['home' => $home, 'away' => $away, 'penHome' => $penHome, 'penAway' => $penAway];
    }
}
