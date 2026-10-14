<?php

declare(strict_types=1);

/*
 * Assessment scope, version 1 (free football-data.org data only).
 *
 * Seasons are the API's season *start year*: 2025 = season 2025/26.
 * Matches outside the period are skipped during import.
 */
return [
    'period' => [
        'from' => '2025-07-01',
        'to' => '2026-07-19',
    ],

    'competitions' => [
        'PL' => [2025],   // Premier League
        'PD' => [2025],   // La Liga (Primera Division)
        'SA' => [2025],   // Serie A
        'BL1' => [2025],  // Bundesliga
        'FL1' => [2025],  // Ligue 1
        'DED' => [2025],  // Eredivisie
        'CL' => [2025],   // Champions League
        'WC' => [2026],   // World Cup
    ],

    // Size of the top-scorers list per competition (goals, assists, penalties).
    'scorers_limit' => 100,
];
