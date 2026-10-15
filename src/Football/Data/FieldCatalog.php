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
            new Field('big_chances_missed', 'Big chances missed', 'Attacking', $ss, 'ss_bigChancesMissed', null),
            new Field('penalties_won', 'Penalties won', 'Attacking', $ss, 'ss_penaltyWon', null),
            new Field('fouled', 'Fouls won', 'Attacking', $ss, 'ss_wasFouled', null),
            new Field('offsides', 'Offsides', 'Attacking', $ss, 'ss_offsides', null),
            // Attacking quality
            new Field('xg', 'Expected goals (xG)', 'Attacking quality', $us, 'us_xg', 'expectedGoals', note: self::XG_MODELS),
            new Field('np_xg', 'Non-penalty xG', 'Attacking quality', $us, 'us_np_xg', null),
            new Field('xa', 'Expected assists (xA)', 'Attacking quality', $us, 'us_xa', 'expectedAssists', note: self::XG_MODELS),
            // SofaScore's own expected goals, in the league data: a fallback for players Understat has no record of.
            new Field('xg_sofascore', 'Expected goals (SofaScore)', 'Attacking quality', $ss, 'ss_expectedGoals', null, note: 'Fallback when Understat has no record; includes penalties; empty when he did not shoot'),
            new Field('xa_sofascore', 'Expected assists (SofaScore)', 'Attacking quality', $ss, 'ss_expectedAssists', null, note: 'Fallback when Understat has no record'),
            new Field('penalties_taken', 'Penalties taken', 'Attacking', $ss, 'ss_penaltiesTaken', null),
            // Creation and passing
            new Field('key_passes', 'Key passes', 'Creation and passing', $ss, 'ss_keyPasses', 'keyPasses'),
            new Field('big_chances_created', 'Big chances created', 'Creation and passing', $ss, 'ss_bigChancesCreated', 'bigChancesCreated'),
            new Field('xg_chain', 'xGChain', 'Creation and passing', $us, 'us_xg_chain', null),
            new Field('xg_buildup', 'xGBuildup', 'Creation and passing', $us, 'us_xg_buildup', null),
            new Field('final_third_passes', 'Accurate final-third passes', 'Creation and passing', $ss, 'ss_accurateFinalThirdPasses', 'accurateFinalThirdPasses'),
            new Field('accurate_passes', 'Accurate passes', 'Creation and passing', $ss, 'ss_accuratePasses', 'accuratePasses'),
            new Field('pass_accuracy', 'Pass accuracy %', 'Creation and passing', $ss, 'ss_accuratePassesPercentage', 'accuratePassesPercentage', per90: false),
            new Field('accurate_crosses', 'Accurate crosses', 'Creation and passing', $ss, 'ss_accurateCrosses', null),
            new Field('successful_dribbles', 'Successful dribbles', 'Creation and passing', $ss, 'ss_successfulDribbles', null),
            new Field('dribble_attempts', 'Dribble attempts', 'Creation and passing', $ss, 'ss_totalContest', null),
            new Field('crosses', 'Crosses', 'Creation and passing', $ss, 'ss_totalCross', null),
            new Field('total_passes', 'Passes', 'Creation and passing', $ss, 'ss_totalPasses', null),
            new Field('inaccurate_passes', 'Inaccurate passes', 'Creation and passing', $ss, 'ss_inaccuratePasses', null),
            new Field('accurate_own_half_passes', 'Accurate passes in own half', 'Creation and passing', $ss, 'ss_accurateOwnHalfPasses', null),
            new Field('own_half_passes', 'Passes in own half', 'Creation and passing', $ss, 'ss_totalOwnHalfPasses', null),
            new Field('opposition_half_passes', 'Passes in the opposition half', 'Creation and passing', $ss, 'ss_totalOppositionHalfPasses', null),
            new Field('chipped_passes', 'Chipped passes', 'Creation and passing', $ss, 'ss_totalChippedPasses', null),
            new Field('accurate_opposition_half_passes', 'Accurate passes in the opposition half', 'Creation and passing', $ss, 'ss_accurateOppositionHalfPasses', null),
            new Field('accurate_long_balls', 'Accurate long balls', 'Creation and passing', $ss, 'ss_accurateLongBalls', null),
            new Field('long_balls', 'Long balls', 'Creation and passing', $ss, 'ss_totalLongBalls', null),
            new Field('accurate_chipped_passes', 'Accurate chipped passes', 'Creation and passing', $ss, 'ss_accurateChippedPasses', null),
            new Field('touches', 'Touches', 'Creation and passing', $ss, 'ss_touches', null),
            new Field('dispossessed', 'Dispossessed', 'Creation and passing', $ss, 'ss_dispossessed', null),
            new Field('possession_lost', 'Possession lost', 'Creation and passing', $ss, 'ss_possessionLost', null),
            // Defending
            new Field('tackles', 'Tackles', 'Defending', $ss, 'ss_tackles', 'tackles'),
            new Field('tackles_won', 'Tackles won', 'Defending', $ss, 'ss_tacklesWon', 'tacklesWon'),
            new Field('interceptions', 'Interceptions', 'Defending', $ss, 'ss_interceptions', 'interceptions'),
            new Field('blocks', 'Blocks', 'Defending', $ss, 'ss_outfielderBlocks', 'outfielderBlocks', note: 'Blocks made, not the player\'s own blocked shots'),
            new Field('clearances', 'Clearances', 'Defending', $ss, 'ss_clearances', 'clearances'),
            new Field('ball_recoveries', 'Ball recoveries', 'Defending', $ss, 'ss_ballRecovery', 'ballRecovery'),
            new Field('possession_won_att_third', 'Possession won in the attacking third', 'Defending', $ss, 'ss_possessionWonAttThird', null),
            new Field('aerial_duels_won', 'Aerial duels won', 'Defending', $ss, 'ss_aerialDuelsWon', 'aerialDuelsWon'),
            new Field('aerial_duels_lost', 'Aerial duels lost', 'Defending', $ss, 'ss_aerialLost', 'aerialLost'),
            new Field('ground_duels_won', 'Ground duels won', 'Defending', $ss, 'ss_groundDuelsWon', null),
            new Field('duels_lost', 'Duels lost', 'Defending', $ss, 'ss_duelLost', null, note: 'Ground and aerial'),
            new Field('dribbled_past', 'Dribbled past', 'Defending', $ss, 'ss_dribbledPast', null),
            new Field('fouls', 'Fouls committed', 'Defending', $ss, 'ss_fouls', null),
            new Field('penalties_conceded', 'Penalties conceded', 'Defending', $ss, 'ss_penaltyConceded', null),
            new Field('own_goals', 'Own goals', 'Defending', $ss, 'ss_ownGoals', null),
            new Field('yellow_cards', 'Yellow cards', 'Defending', $ss, 'ss_yellowCards', null),
            new Field('red_cards', 'Red cards', 'Defending', $ss, 'ss_redCards', null),
            new Field('errors_leading_to_goal', 'Errors leading to goal', 'Defending', $ss, 'ss_errorLeadToGoal', 'errorLeadToGoal'),
            new Field('errors_leading_to_shot', 'Errors leading to shot', 'Defending', $ss, 'ss_errorLeadToShot', null),
            new Field('aerial_win_pct', 'Aerial duels won %', 'Defending', Origin::Derived, null, null, per90: false, note: 'won / (won + lost), at least 20 duels', inputs: [$ss]),
            new Field('tackle_success_pct', 'Tackle success %', 'Defending', Origin::Derived, null, null, per90: false, note: 'tackles won / tackles, at least 15 tackles', inputs: [$ss]),
            // Goalkeeping
            new Field('saves', 'Saves', 'Goalkeeping', $ss, 'ss_saves', 'saves', goalkeeper: true),
            new Field('goals_conceded', 'Goals conceded', 'Goalkeeping', $ss, 'ss_goalsConceded', 'goalsConceded', goalkeeper: true),
            new Field('save_pct', 'Save %', 'Goalkeeping', Origin::Derived, null, null, goalkeeper: true, per90: false, note: 'saves / (saves + goals conceded)', inputs: [$ss]),
            new Field('goals_prevented', 'Goals prevented', 'Goalkeeping', $ss, 'ss_goalsPrevented', 'goalsPrevented', goalkeeper: true, note: 'SofaScore post-shot model'),
            new Field('clean_sheets', 'Clean sheets', 'Goalkeeping', $ss, 'ss_cleanSheet', 'cleanSheet', goalkeeper: true, note: 'Mostly a team outcome'),
            new Field('punches', 'Punches', 'Goalkeeping', $ss, 'ss_punches', null, goalkeeper: true),
            new Field('high_claims', 'High claims', 'Goalkeeping', $ss, 'ss_highClaims', null, goalkeeper: true),
            new Field('crosses_not_claimed', 'Crosses not claimed', 'Goalkeeping', $ss, 'ss_crossesNotClaimed', null, goalkeeper: true),
            new Field('runs_out', 'Runs out', 'Goalkeeping', $ss, 'ss_runsOut', null, goalkeeper: true),
            new Field('successful_runs_out', 'Successful runs out', 'Goalkeeping', $ss, 'ss_successfulRunsOut', null, goalkeeper: true),
            new Field('penalties_faced', 'Penalties faced', 'Goalkeeping', $ss, 'ss_penaltyFaced', null, goalkeeper: true),
            new Field('penalty_saves', 'Penalty saves', 'Goalkeeping', $ss, 'ss_penaltySave', null, goalkeeper: true),
            // Physical
            new Field('distance_km', 'Distance covered (km)', 'Physical', $ss, 'ss_kilometersCovered', null),
            new Field('sprints', 'Sprints', 'Physical', $ss, 'ss_numberOfSprints', null),
        ];

        return array_column(array_map(static fn (Field $f) => ['k' => $f->key, 'f' => $f], $fields), 'f', 'k');
    }
}
