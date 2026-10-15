<?php

declare(strict_types=1);

namespace App\Football\Standards;

use App\Football\Scoring\Impact\ImpactModel;

/**
 * Every statistic in the data, sorted into what the standards may use and what they may not.
 *
 * Candidates: counts per 90 and success rates whose direction is clear from their definition
 * (a goal, an accurate pass, a won duel count for a player; a lost duel, an error, a card
 * against). Whether a candidate is used for a role, and how much it counts, is decided by the
 * data (StandardsDeriver), not here.
 *
 * Left out, each with its reason (excluded()): volumes of attempts (their successes and
 * success rates are candidates), sums and subsets of other statistics (counted once), team
 * outcomes (not the player's own doing), other providers' ratings, and statistics without a
 * direction.
 */
final class StatisticCatalog
{
    /** @var array<string, Statistic>|null */
    private static ?array $statistics = null;

    /** @return array<string, Statistic> */
    public static function all(): array
    {
        return self::$statistics ??= self::build();
    }

    public static function get(string $key): Statistic
    {
        return self::all()[$key] ?? throw new \InvalidArgumentException("Unknown statistic {$key}");
    }

    /**
     * Statistics in the data that are not candidates, and why.
     *
     * @return array<string, string> statistic => reason
     */
    public static function excluded(): array
    {
        $attempts = 'A volume of attempts: its successes and its success rate are candidates instead';
        $part = 'Part or sum of another statistic, which is a candidate: counted once';
        $team = 'A team outcome while he is on the pitch, not his own doing (in the contribution: team defence)';

        return [
            'Goals (all)' => 'Non-penalty goals are the candidate: a penalty is a set chance, valued by penalty xG',
            'Goals inside / outside the box, headed, left / right foot, free kicks, penalty goals' => $part,
            'Goals + assists' => $part,
            'Expected goals (incl. penalties)' => 'Non-penalty xG is the candidate',
            'Expected goals and expected assists (SofaScore)' => 'A second model of the same thing: Understat\'s xG and xA are the candidates; SofaScore\'s only fill gaps in the contribution',
            'xGChain' => 'The sum of xGBuildup and his own shots\' xG and key passes\' xA, which are candidates: counted once',
            'Shots, shots off target, shots inside / outside the box, shots from set pieces' => $attempts,
            'Passes, long balls, chipped passes, crosses, dribble attempts, own-half / opposition-half passes attempted' => $attempts,
            'Tackles' => $attempts . ' (tackles won)',
            'Total duels won, total duels won %' => $part . ' (ground and aerial duels won, and their success rates)',
            'Duels lost' => $part . ' (aerial duels lost; ground duels lost are in the ground-duel success rate)',
            'Accurate passes, pass accuracy %' => $part . ' (accurate passes in own half and in the opposition half, and their accuracy)',
            'Possession lost' => $part . ' (dispossessed, inaccurate passes, and failed dribbles in the dribble success rate)',
            'Goal conversion % (SofaScore)' => 'Includes penalties: goals per non-penalty shot is the candidate',
            'Non-penalty goals minus xG' => 'The difference of two candidates (non-penalty goals, npxG)',
            'Hit the woodwork, own shots blocked' => 'Outcomes of his own shots without a direction (neither a goal nor a miss by his doing); shots on target % and goals per shot cover his shooting',
            'Direct red cards' => $part . ' (red cards)',
            'Understat\'s shots, key passes and non-penalty goals' => 'A second provider of the same counts (FBref and SofaScore)',
            'Penalties taken, on target, hit the post, missed; penalty saves %' => 'Rare, and a set chance, not open play',
            'Goals conceded and clean sheets of outfield players' => $team,
            'Goals conceded, clean sheets (goalkeepers)' => $team . '; goals prevented measures the goalkeeper',
            'Saves, saves inside / outside the box, caught, parried' => 'Depend on how many shots his defence allows: goals prevented measures the goalkeeper',
            'Save %' => 'Depends on how hard the shots his defence allows are; goals prevented corrects for that',
            'Penalties faced, goal kicks' => 'No direction: how often, not how well',
            'Runs out' => $attempts . ' (successful runs out)',
            'SofaScore rating, EA SPORTS FC attributes' => 'Other providers\' ratings: the standards are built from the statistics themselves (EA FC is used only for positions)',
            'Scoring frequency, set-piece conversion' => 'Rates of goals over time or set pieces: non-penalty goals per 90 and goals per non-penalty shot are the candidates',
            'Second yellow cards' => $part . ' (red cards)',
            'FBref yellow and red cards' => 'A second provider of the same counts (SofaScore)',
            'Top speed' => 'A single peak, not a count or a rate',
            'Pass to assist, total attempt assist' => $part . ' (key passes, assists)',
            'Appearances, starts, team of the week' => 'Playing time is in the contribution (season totals and replacement level)',
        ];
    }

    /** @return array<string, Statistic> */
    private static function build(): array
    {
        // Understat has no record of some players (name-matching gaps): SofaScore estimates fill in,
        // scaled per role to Understat's level on the players both cover (as in the contribution).
        $penaltyXg = (new ImpactModel())->penaltyXg;

        $statistics = [
            // Scoring and shooting
            self::per90('np_goals', 'Non-penalty goals', 'np_goals'),
            self::per90('np_xg', 'Non-penalty xG', 'np_xg', proxy: [['xg_sofascore', 1.0], ['penalties_taken', -$penaltyXg]]),
            self::per90('shots_on_target', 'Shots on target', 'shots_on_target'),
            self::rate('shot_accuracy', 'Shots on target %', ['shots_on_target'], ['shots']),
            self::rate('shot_conversion', 'Goals per non-penalty shot', ['np_goals'], ['shots', '-penalties_taken']),
            self::per90('big_chances_missed', 'Big chances missed', 'big_chances_missed', -1),
            self::per90('offsides', 'Offsides', 'offsides', -1),
            self::per90('penalties_won', 'Penalties won', 'penalties_won'),
            self::per90('fouled', 'Fouls won', 'fouled'),
            // Creating
            self::per90('assists', 'Assists', 'assists'),
            self::per90('xa', 'Expected assists (xA)', 'xa', proxy: [['xa_sofascore', 1.0]]),
            self::per90('key_passes', 'Key passes', 'key_passes'),
            self::per90('big_chances_created', 'Big chances created', 'big_chances_created'),
            self::per90('accurate_crosses', 'Accurate crosses', 'accurate_crosses'),
            self::rate('cross_accuracy', 'Cross accuracy %', ['accurate_crosses'], ['crosses']),
            // Passing and ball progression
            self::per90('xg_buildup', 'xGBuildup', 'xg_buildup', note: 'xG of the possessions he helped build, without his own shots and key passes', proxy: [['accurate_passes', 1.0]]),
            self::per90('inaccurate_passes', 'Inaccurate passes', 'inaccurate_passes', -1),
            self::per90('accurate_own_half_passes', 'Accurate passes in own half', 'accurate_own_half_passes'),
            self::rate('own_half_pass_accuracy', 'Pass accuracy in own half %', ['accurate_own_half_passes'], ['own_half_passes']),
            self::per90('accurate_opposition_half_passes', 'Accurate passes in the opposition half', 'accurate_opposition_half_passes'),
            self::rate('opposition_half_pass_accuracy', 'Pass accuracy in the opposition half %', ['accurate_opposition_half_passes'], ['opposition_half_passes']),
            self::per90('final_third_passes', 'Accurate final-third passes', 'final_third_passes'),
            self::per90('accurate_long_balls', 'Accurate long balls', 'accurate_long_balls'),
            self::rate('long_ball_accuracy', 'Long-ball accuracy %', ['accurate_long_balls'], ['long_balls']),
            self::per90('accurate_chipped_passes', 'Accurate chipped passes', 'accurate_chipped_passes'),
            self::rate('chipped_pass_accuracy', 'Chipped pass accuracy %', ['accurate_chipped_passes'], ['chipped_passes']),
            self::per90('touches', 'Touches', 'touches'),
            self::per90('successful_dribbles', 'Successful dribbles', 'successful_dribbles'),
            self::rate('dribble_success', 'Dribble success %', ['successful_dribbles'], ['dribble_attempts']),
            self::per90('dispossessed', 'Dispossessed', 'dispossessed', -1),
            // Defending and duels
            self::per90('tackles_won', 'Tackles won', 'tackles_won'),
            self::rate('tackle_success', 'Tackle success %', ['tackles_won'], ['tackles']),
            self::per90('interceptions', 'Interceptions', 'interceptions'),
            self::per90('blocks', 'Blocks', 'blocks'),
            self::per90('clearances', 'Clearances', 'clearances'),
            self::per90('ball_recoveries', 'Ball recoveries', 'ball_recoveries'),
            self::per90('possession_won_att_third', 'Possession won in the attacking third', 'possession_won_att_third'),
            self::per90('ground_duels_won', 'Ground duels won', 'ground_duels_won'),
            self::rate('ground_duel_success', 'Ground duels won %', ['ground_duels_won'], ['ground_duels_won', 'duels_lost', '-aerial_duels_lost']),
            self::per90('aerial_duels_won', 'Aerial duels won', 'aerial_duels_won'),
            self::per90('aerial_duels_lost', 'Aerial duels lost', 'aerial_duels_lost', -1),
            self::rate('aerial_duel_success', 'Aerial duels won %', ['aerial_duels_won'], ['aerial_duels_won', 'aerial_duels_lost']),
            self::per90('dribbled_past', 'Dribbled past', 'dribbled_past', -1),
            self::per90('fouls', 'Fouls committed', 'fouls', -1),
            self::per90('yellow_cards', 'Yellow cards', 'yellow_cards', -1),
            self::per90('red_cards', 'Red cards', 'red_cards', -1),
            self::per90('penalties_conceded', 'Penalties conceded', 'penalties_conceded', -1),
            self::per90('errors_leading_to_shot', 'Errors leading to a shot', 'errors_leading_to_shot', -1),
            self::per90('errors_leading_to_goal', 'Errors leading to a goal', 'errors_leading_to_goal', -1),
            self::per90('own_goals', 'Own goals', 'own_goals', -1),
            // Physical
            self::per90('distance_km', 'Distance covered', 'distance_km'),
            self::per90('sprints', 'Sprints', 'sprints'),
            // Goalkeeping
            self::per90('goals_prevented', 'Goals prevented', 'goals_prevented', note: 'Post-shot xG faced minus goals conceded'),
            self::per90('high_claims', 'High claims', 'high_claims'),
            self::per90('punches', 'Punches', 'punches'),
            self::per90('crosses_not_claimed', 'Crosses not claimed', 'crosses_not_claimed', -1),
            self::per90('successful_runs_out', 'Successful runs out', 'successful_runs_out'),
            self::rate('runs_out_success', 'Runs out success %', ['successful_runs_out'], ['runs_out']),
        ];

        return array_column(array_map(static fn (Statistic $s) => ['k' => $s->key, 's' => $s], $statistics), 's', 'k');
    }

    /** @param list<array{0: string, 1: float}> $proxy */
    private static function per90(string $key, string $label, string $field, int $direction = 1, ?string $note = null, array $proxy = []): Statistic
    {
        return new Statistic($key, $label, Statistic::PER90, [$field], $direction, note: $note, proxy: $proxy);
    }

    /**
     * @param non-empty-list<string> $successes
     * @param list<string>           $attempts
     */
    private static function rate(string $key, string $label, array $successes, array $attempts): Statistic
    {
        return new Statistic($key, $label, Statistic::RATE, $successes, 1, $attempts);
    }
}
