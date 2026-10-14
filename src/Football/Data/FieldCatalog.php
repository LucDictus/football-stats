<?php

declare(strict_types=1);

namespace App\Football\Data;

/**
 * The statistics available to the model, with their source per competition.
 *
 * League data: Top-5 dataset (FBref, Understat, SofaScore columns).
 * Champions League data: SofaScore season statistics (same metric names as the ss_* columns).
 */
final class FieldCatalog
{
    private const XG_MODELS = 'League xG/xA is Understat\'s model, CL xG/xA is SofaScore\'s: different models, not directly addable';

    /** @var array<string, Field>|null */
    private static ?array $fields = null;

    /** @return array<string, Field> */
    public static function all(): array
    {
        return self::$fields ??= self::build();
    }

    public static function get(string $key): Field
    {
        return self::all()[$key] ?? throw new \InvalidArgumentException("Unknown field {$key}");
    }

    /**
     * Statistics the roadmap mentions but no free source provides (yet).
     *
     * @return array<string, string> label => reason
     */
    public static function unavailable(): array
    {
        return [
            'Progressive passes / carries' => 'FBref removed progression data in 2026; final-third passes and xGBuildup are the nearest substitutes',
            'Non-penalty xG in the Champions League' => 'SofaScore CL statistics have xG but no penalty split',
            'xGChain / xGBuildup in the Champions League' => 'Understat does not cover the Champions League',
            'Match-level lineups and minutes' => 'Only season totals are available for free; needed for consistency and match-impact analysis',
            'Trophies and individual awards' => 'Not imported yet (roadmap phase 7)',
        ];
    }

    /** @return array<string, Field> */
    private static function build(): array
    {
        $fb = Origin::Fbref;
        $us = Origin::Understat;
        $ss = Origin::Sofascore;
        $fields = [
            // Playing time
            new Field('minutes', 'Minutes', 'Playing time', $fb, 'playing_time_min', 'minutesPlayed', per90: false),
            new Field('appearances', 'Appearances', 'Playing time', $fb, 'playing_time_mp', 'appearances', per90: false),
            new Field('starts', 'Starts', 'Playing time', $fb, 'playing_time_starts', 'matchesStarted', per90: false),
            // Attacking output
            new Field('goals', 'Goals', 'Attacking', $fb, 'fb_gls', 'goals', note: 'Includes penalties'),
            new Field('penalty_goals', 'Penalty goals', 'Attacking', $ss, 'ss_penaltyGoals', 'penaltyGoals'),
            new Field('np_goals', 'Non-penalty goals', 'Attacking', Origin::Derived, null, null, note: 'FBref goals minus SofaScore penalty goals when SofaScore is trusted; otherwise Understat\'s own non-penalty goals', inputs: [$fb, $ss]),
            new Field('assists', 'Assists', 'Attacking', $fb, 'fb_ast', 'assists'),
            new Field('shots', 'Shots', 'Attacking', $fb, 'fb_sh', 'totalShots'),
            new Field('shots_on_target', 'Shots on target', 'Attacking', $fb, 'fb_sot', 'shotsOnTarget'),
            // Attacking quality
            new Field('xg', 'Expected goals (xG)', 'Attacking quality', $us, 'us_xg', 'expectedGoals', note: self::XG_MODELS),
            new Field('np_xg', 'Non-penalty xG', 'Attacking quality', $us, 'us_np_xg', null),
            new Field('xa', 'Expected assists (xA)', 'Attacking quality', $us, 'us_xa', 'expectedAssists', note: self::XG_MODELS),
            // SofaScore's own expected goals, in the league data: a fallback for players Understat has no record of.
            new Field('xg_sofascore', 'Expected goals (SofaScore)', 'Attacking quality', $ss, 'ss_expectedGoals', null, note: 'Fallback when Understat has no record; includes penalties; empty when he did not shoot'),
            new Field('xa_sofascore', 'Expected assists (SofaScore)', 'Attacking quality', $ss, 'ss_expectedAssists', null, note: 'Fallback when Understat has no record'),
            new Field('penalties_taken', 'Penalties taken', 'Attacking', $ss, 'ss_penaltiesTaken', null, per90: false),
            // Creation and passing
            new Field('key_passes', 'Key passes', 'Creation and passing', $ss, 'ss_keyPasses', 'keyPasses'),
            new Field('big_chances_created', 'Big chances created', 'Creation and passing', $ss, 'ss_bigChancesCreated', 'bigChancesCreated'),
            new Field('xg_chain', 'xGChain', 'Creation and passing', $us, 'us_xg_chain', null),
            new Field('xg_buildup', 'xGBuildup', 'Creation and passing', $us, 'us_xg_buildup', null),
            new Field('final_third_passes', 'Accurate final-third passes', 'Creation and passing', $ss, 'ss_accurateFinalThirdPasses', 'accurateFinalThirdPasses'),
            new Field('accurate_passes', 'Accurate passes', 'Creation and passing', $ss, 'ss_accuratePasses', 'accuratePasses'),
            new Field('pass_accuracy', 'Pass accuracy %', 'Creation and passing', $ss, 'ss_accuratePassesPercentage', 'accuratePassesPercentage', per90: false),
            // Defending
            new Field('tackles', 'Tackles', 'Defending', $ss, 'ss_tackles', 'tackles'),
            new Field('tackles_won', 'Tackles won', 'Defending', $ss, 'ss_tacklesWon', 'tacklesWon'),
            new Field('interceptions', 'Interceptions', 'Defending', $ss, 'ss_interceptions', 'interceptions'),
            new Field('blocks', 'Blocks', 'Defending', $ss, 'ss_outfielderBlocks', 'outfielderBlocks', note: 'Blocks made, not the player\'s own blocked shots'),
            new Field('clearances', 'Clearances', 'Defending', $ss, 'ss_clearances', 'clearances'),
            new Field('ball_recoveries', 'Ball recoveries', 'Defending', $ss, 'ss_ballRecovery', 'ballRecovery'),
            new Field('aerial_duels_won', 'Aerial duels won', 'Defending', $ss, 'ss_aerialDuelsWon', 'aerialDuelsWon'),
            new Field('aerial_duels_lost', 'Aerial duels lost', 'Defending', $ss, 'ss_aerialLost', 'aerialLost'),
            new Field('errors_leading_to_goal', 'Errors leading to goal', 'Defending', $ss, 'ss_errorLeadToGoal', 'errorLeadToGoal'),
            new Field('aerial_win_pct', 'Aerial duels won %', 'Defending', Origin::Derived, null, null, per90: false, note: 'won / (won + lost), at least 20 duels', inputs: [$ss]),
            new Field('tackle_success_pct', 'Tackle success %', 'Defending', Origin::Derived, null, null, per90: false, note: 'tackles won / tackles, at least 15 tackles', inputs: [$ss]),
            // Goalkeeping
            new Field('saves', 'Saves', 'Goalkeeping', $ss, 'ss_saves', 'saves', goalkeeper: true),
            new Field('goals_conceded', 'Goals conceded', 'Goalkeeping', $ss, 'ss_goalsConceded', 'goalsConceded', goalkeeper: true),
            new Field('save_pct', 'Save %', 'Goalkeeping', Origin::Derived, null, null, goalkeeper: true, per90: false, note: 'saves / (saves + goals conceded)', inputs: [$ss]),
            new Field('goals_prevented', 'Goals prevented', 'Goalkeeping', $ss, 'ss_goalsPrevented', 'goalsPrevented', goalkeeper: true, note: 'SofaScore post-shot model'),
            new Field('clean_sheets', 'Clean sheets', 'Goalkeeping', $ss, 'ss_cleanSheet', 'cleanSheet', goalkeeper: true, note: 'Mostly a team outcome'),
        ];

        return array_column(array_map(static fn (Field $f) => ['k' => $f->key, 'f' => $f], $fields), 'f', 'k');
    }
}
