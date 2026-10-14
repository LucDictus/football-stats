<?php

declare(strict_types=1);

/*
 * Field registry for the MVP dataset.
 *
 * One entry per statistic. Each entry lists the sources that provide it:
 *
 *   source       key from 'sources' below
 *   column       column in that source (null = no source yet)
 *   competitions competition codes the source covers for this field
 *   level        'stint'  = per club stint, summed over a player's clubs
 *                'season' = already a season total in the source; duplicated on
 *                           every stint row, so it is taken ONCE (never summed)
 *                'derived' = calculated in the importer from other fields
 *                'match'  = match-level data (football-data.org match records)
 *   note         known caveats
 *
 * short/format: column header and number format in the viewer (int | dec | pct).
 *
 * applies_to: 'all' or 'GK'. Coverage for GK fields is only counted over goalkeepers,
 * so an outfield player without saves is "not applicable", not "missing".
 *
 * Missing values stay NULL everywhere. Nothing in the pipeline turns NULL into 0.
 */

$top5 = ['PL', 'PD', 'SA', 'BL1', 'FL1'];

return [
    'sources' => [
        'top5' => [
            'label' => 'Top-5 Football Dataset (m-mahadi), season 2025/26',
            'url' => 'https://github.com/m-mahadi/top5-football-dataset',
            'origins' => ['fb_' => 'FBref', 'us_' => 'Understat', 'ss_' => 'SofaScore'],
        ],
        'football-data' => [
            'label' => 'football-data.org v4 (free tier)',
            'url' => 'https://www.football-data.org',
        ],
    ],

    'fields' => [
        // ---------------- Playing time (essential) ----------------
        'minutes' => ['short' => 'Min', 'format' => 'int', 'label' => 'Minutes', 'category' => 'Playing time', 'priority' => 'Essential', 'applies_to' => 'all', 'sources' => [
            ['source' => 'top5', 'column' => 'playing_time_min', 'competitions' => $top5, 'level' => 'stint'],
            ['source' => null, 'column' => null, 'competitions' => ['CL'], 'level' => null, 'note' => 'No free source for CL minutes yet'],
        ]],
        'appearances' => ['short' => 'Apps', 'format' => 'int', 'label' => 'Appearances', 'category' => 'Playing time', 'priority' => 'Essential', 'applies_to' => 'all', 'sources' => [
            ['source' => 'top5', 'column' => 'playing_time_mp', 'competitions' => $top5, 'level' => 'stint'],
            ['source' => 'football-data', 'column' => 'played_matches', 'competitions' => ['CL'], 'level' => 'season', 'note' => 'Only players in the CL top-scorer list'],
        ]],
        'starts' => ['short' => 'Starts', 'format' => 'int', 'label' => 'Starts', 'category' => 'Playing time', 'priority' => 'Essential', 'applies_to' => 'all', 'sources' => [
            ['source' => 'top5', 'column' => 'playing_time_starts', 'competitions' => $top5, 'level' => 'stint'],
            ['source' => null, 'column' => null, 'competitions' => ['CL'], 'level' => null],
        ]],

        // ---------------- Attacking (essential) ----------------
        'goals' => ['short' => 'G', 'format' => 'int', 'label' => 'Goals', 'category' => 'Attacking', 'priority' => 'Essential', 'applies_to' => 'all', 'sources' => [
            ['source' => 'top5', 'column' => 'fb_gls', 'competitions' => $top5, 'level' => 'stint'],
            ['source' => 'football-data', 'column' => 'goals', 'competitions' => ['CL'], 'level' => 'season', 'note' => 'Only players in the CL top-scorer list'],
        ]],
        'assists' => ['short' => 'A', 'format' => 'int', 'label' => 'Assists', 'category' => 'Attacking', 'priority' => 'Essential', 'applies_to' => 'all', 'sources' => [
            ['source' => 'top5', 'column' => 'fb_ast', 'competitions' => $top5, 'level' => 'stint'],
            ['source' => 'football-data', 'column' => 'assists', 'competitions' => ['CL'], 'level' => 'season', 'note' => 'Only players in the CL top-scorer list'],
        ]],
        'penalty_goals' => ['short' => 'PenG', 'format' => 'int', 'label' => 'Penalty goals', 'category' => 'Attacking', 'priority' => 'Essential', 'applies_to' => 'all', 'sources' => [
            ['source' => 'top5', 'column' => 'ss_penaltyGoals', 'competitions' => $top5, 'level' => 'season'],
        ]],
        'xg' => ['short' => 'xG', 'format' => 'dec', 'label' => 'Expected goals (xG)', 'category' => 'Attacking', 'priority' => 'Essential', 'applies_to' => 'all', 'sources' => [
            ['source' => 'top5', 'column' => 'us_xg', 'competitions' => $top5, 'level' => 'season', 'note' => 'Understat. SofaScore xG (ss_expectedGoals) is not used: it has no zeros, so absent likely means 0 there'],
            ['source' => null, 'column' => null, 'competitions' => ['CL'], 'level' => null],
        ]],
        'np_xg' => ['short' => 'npxG', 'format' => 'dec', 'label' => 'Non-penalty xG', 'category' => 'Attacking', 'priority' => 'Essential', 'applies_to' => 'all', 'sources' => [
            ['source' => 'top5', 'column' => 'us_np_xg', 'competitions' => $top5, 'level' => 'season'],
        ]],
        'xa' => ['short' => 'xA', 'format' => 'dec', 'label' => 'Expected assists (xA)', 'category' => 'Attacking', 'priority' => 'Essential', 'applies_to' => 'all', 'sources' => [
            ['source' => 'top5', 'column' => 'us_xa', 'competitions' => $top5, 'level' => 'season'],
            ['source' => null, 'column' => null, 'competitions' => ['CL'], 'level' => null],
        ]],
        'shots' => ['short' => 'Sh', 'format' => 'int', 'label' => 'Shots', 'category' => 'Attacking', 'priority' => 'Essential', 'applies_to' => 'all', 'sources' => [
            ['source' => 'top5', 'column' => 'fb_sh', 'competitions' => $top5, 'level' => 'stint'],
            ['source' => null, 'column' => null, 'competitions' => ['CL'], 'level' => null],
        ]],
        'shots_on_target' => ['short' => 'SoT', 'format' => 'int', 'label' => 'Shots on target', 'category' => 'Attacking', 'priority' => 'Essential', 'applies_to' => 'all', 'sources' => [
            ['source' => 'top5', 'column' => 'fb_sot', 'competitions' => $top5, 'level' => 'stint'],
        ]],

        // ---------------- Passing and creation (high) ----------------
        'key_passes' => ['short' => 'KP', 'format' => 'int', 'label' => 'Key passes', 'category' => 'Passing and creation', 'priority' => 'High', 'applies_to' => 'all', 'sources' => [
            ['source' => 'top5', 'column' => 'ss_keyPasses', 'competitions' => $top5, 'level' => 'season', 'note' => 'Alternative: us_key_passes (Understat)'],
        ]],
        'big_chances_created' => ['short' => 'BCC', 'format' => 'int', 'label' => 'Big chances created', 'category' => 'Passing and creation', 'priority' => 'High', 'applies_to' => 'all', 'sources' => [
            ['source' => 'top5', 'column' => 'ss_bigChancesCreated', 'competitions' => $top5, 'level' => 'season'],
        ]],
        'xg_chain' => ['short' => 'xGC', 'format' => 'dec', 'label' => 'xGChain', 'category' => 'Passing and creation', 'priority' => 'High', 'applies_to' => 'all', 'sources' => [
            ['source' => 'top5', 'column' => 'us_xg_chain', 'competitions' => $top5, 'level' => 'season'],
        ]],
        'xg_buildup' => ['short' => 'xGB', 'format' => 'dec', 'label' => 'xGBuildup', 'category' => 'Passing and creation', 'priority' => 'High', 'applies_to' => 'all', 'sources' => [
            ['source' => 'top5', 'column' => 'us_xg_buildup', 'competitions' => $top5, 'level' => 'season'],
        ]],
        'final_third_passes' => ['short' => 'F3P', 'format' => 'int', 'label' => 'Accurate final-third passes', 'category' => 'Passing and creation', 'priority' => 'High', 'applies_to' => 'all', 'sources' => [
            ['source' => 'top5', 'column' => 'ss_accurateFinalThirdPasses', 'competitions' => $top5, 'level' => 'season'],
        ]],
        'accurate_passes' => ['short' => 'AccP', 'format' => 'int', 'label' => 'Accurate passes', 'category' => 'Passing and creation', 'priority' => 'High', 'applies_to' => 'all', 'sources' => [
            ['source' => 'top5', 'column' => 'ss_accuratePasses', 'competitions' => $top5, 'level' => 'season'],
        ]],
        'pass_accuracy' => ['short' => 'Pass%', 'format' => 'pct', 'label' => 'Pass accuracy %', 'category' => 'Passing and creation', 'priority' => 'High', 'applies_to' => 'all', 'sources' => [
            ['source' => 'top5', 'column' => 'ss_accuratePassesPercentage', 'competitions' => $top5, 'level' => 'season'],
        ]],
        'progressive_passes' => ['short' => 'PrgP', 'format' => 'int', 'label' => 'Progressive passes', 'category' => 'Passing and creation', 'priority' => 'High', 'applies_to' => 'all', 'sources' => [
            ['source' => null, 'column' => null, 'competitions' => [], 'level' => null, 'note' => 'Not in any free source: FBref no longer publishes progression. Final-third passes and xGBuildup are the nearest substitutes'],
        ]],

        // ---------------- Defending (high) ----------------
        'tackles' => ['short' => 'Tkl', 'format' => 'int', 'label' => 'Tackles', 'category' => 'Defending', 'priority' => 'High', 'applies_to' => 'all', 'sources' => [
            ['source' => 'top5', 'column' => 'ss_tackles', 'competitions' => $top5, 'level' => 'season'],
        ]],
        'tackles_won' => ['short' => 'TklW', 'format' => 'int', 'label' => 'Tackles won', 'category' => 'Defending', 'priority' => 'High', 'applies_to' => 'all', 'sources' => [
            ['source' => 'top5', 'column' => 'ss_tacklesWon', 'competitions' => $top5, 'level' => 'season'],
        ]],
        'interceptions' => ['short' => 'Int', 'format' => 'int', 'label' => 'Interceptions', 'category' => 'Defending', 'priority' => 'High', 'applies_to' => 'all', 'sources' => [
            ['source' => 'top5', 'column' => 'ss_interceptions', 'competitions' => $top5, 'level' => 'season'],
        ]],
        'blocks' => ['short' => 'Blk', 'format' => 'int', 'label' => 'Blocks', 'category' => 'Defending', 'priority' => 'High', 'applies_to' => 'all', 'sources' => [
            ['source' => 'top5', 'column' => 'ss_outfielderBlocks', 'competitions' => $top5, 'level' => 'season', 'note' => 'Blocks made. Not ss_blockedShots, which are the player\'s own shots that got blocked'],
        ]],
        'clearances' => ['short' => 'Clr', 'format' => 'int', 'label' => 'Clearances', 'category' => 'Defending', 'priority' => 'High', 'applies_to' => 'all', 'sources' => [
            ['source' => 'top5', 'column' => 'ss_clearances', 'competitions' => $top5, 'level' => 'season'],
        ]],
        'ball_recoveries' => ['short' => 'Rec', 'format' => 'int', 'label' => 'Ball recoveries', 'category' => 'Defending', 'priority' => 'High', 'applies_to' => 'all', 'sources' => [
            ['source' => 'top5', 'column' => 'ss_ballRecovery', 'competitions' => $top5, 'level' => 'season'],
        ]],
        'aerial_duels_won' => ['short' => 'AerW', 'format' => 'int', 'label' => 'Aerial duels won', 'category' => 'Defending', 'priority' => 'High', 'applies_to' => 'all', 'sources' => [
            ['source' => 'top5', 'column' => 'ss_aerialDuelsWon', 'competitions' => $top5, 'level' => 'season'],
        ]],
        'aerial_duels_lost' => ['short' => 'AerL', 'format' => 'int', 'label' => 'Aerial duels lost', 'category' => 'Defending', 'priority' => 'High', 'applies_to' => 'all', 'sources' => [
            ['source' => 'top5', 'column' => 'ss_aerialLost', 'competitions' => $top5, 'level' => 'season'],
        ]],
        'errors_leading_to_goal' => ['short' => 'ErrG', 'format' => 'int', 'label' => 'Errors leading to goal', 'category' => 'Defending', 'priority' => 'High', 'applies_to' => 'all', 'sources' => [
            ['source' => 'top5', 'column' => 'ss_errorLeadToGoal', 'competitions' => $top5, 'level' => 'season'],
        ]],

        // ---------------- Goalkeeping (high) ----------------
        'saves' => ['short' => 'Sv', 'format' => 'int', 'label' => 'Saves', 'category' => 'Goalkeeping', 'priority' => 'High', 'applies_to' => 'GK', 'sources' => [
            ['source' => 'top5', 'column' => 'ss_saves', 'competitions' => $top5, 'level' => 'season'],
        ]],
        'goals_conceded' => ['short' => 'GA', 'format' => 'int', 'label' => 'Goals conceded', 'category' => 'Goalkeeping', 'priority' => 'High', 'applies_to' => 'GK', 'sources' => [
            ['source' => 'top5', 'column' => 'ss_goalsConceded', 'competitions' => $top5, 'level' => 'season'],
        ]],
        'save_pct' => ['short' => 'Sv%', 'format' => 'pct', 'label' => 'Save %', 'category' => 'Goalkeeping', 'priority' => 'High', 'applies_to' => 'GK', 'sources' => [
            ['source' => 'top5', 'column' => null, 'competitions' => $top5, 'level' => 'derived', 'note' => 'saves / (saves + goals_conceded); NULL if either is missing'],
        ]],
        'goals_prevented' => ['short' => 'GPrev', 'format' => 'dec', 'label' => 'Goals prevented', 'category' => 'Goalkeeping', 'priority' => 'High', 'applies_to' => 'GK', 'sources' => [
            ['source' => 'top5', 'column' => 'ss_goalsPrevented', 'competitions' => $top5, 'level' => 'season', 'note' => 'SofaScore post-shot model'],
        ]],
        'clean_sheets' => ['short' => 'CS', 'format' => 'int', 'label' => 'Clean sheets', 'category' => 'Goalkeeping', 'priority' => 'High', 'applies_to' => 'GK', 'sources' => [
            ['source' => 'top5', 'column' => 'ss_cleanSheet', 'competitions' => $top5, 'level' => 'season'],
        ]],

        // ---------------- Match context (high) ----------------
        'opponent_strength' => ['short' => 'Opp', 'format' => 'dec', 'label' => 'Opponent strength', 'category' => 'Match context', 'priority' => 'High', 'applies_to' => 'all', 'sources' => [
            ['source' => 'football-data', 'column' => 'match_records.home_strength / away_strength', 'competitions' => ['PL', 'PD', 'SA', 'BL1', 'FL1', 'CL'], 'level' => 'match', 'note' => 'Team level, from the final table. Not linked to individual player performances yet'],
        ]],
        'result' => ['short' => 'Res', 'format' => 'int', 'label' => 'Result', 'category' => 'Match context', 'priority' => 'High', 'applies_to' => 'all', 'sources' => [
            ['source' => 'football-data', 'column' => 'match_records.home_score / away_score', 'competitions' => ['PL', 'PD', 'SA', 'BL1', 'FL1', 'CL'], 'level' => 'match'],
        ]],

        // ---------------- Awards and trophies (later) ----------------
        'awards' => ['short' => 'Awd', 'format' => 'int', 'label' => 'Individual awards and trophies', 'category' => 'Awards and trophies', 'priority' => 'Later', 'applies_to' => 'all', 'sources' => [
            ['source' => null, 'column' => null, 'competitions' => [], 'level' => null, 'note' => 'Planned for a later version'],
        ]],
    ],
];
