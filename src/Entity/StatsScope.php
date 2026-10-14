<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Which matches a set of statistics covers. Kept apart until the competition-context phase
 * decides how to combine them, so a Champions League goal is never counted twice.
 */
enum StatsScope: string
{
    /** All domestic league matches in the top-5 leagues (several leagues after a transfer). */
    case League = 'league';
    /** Champions League from the league phase on. */
    case ChampionsLeague = 'CL';
}
