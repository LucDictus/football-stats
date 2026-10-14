<?php

declare(strict_types=1);

namespace App\Football\Scoring\Impact;

/**
 * Contribution in a common unit, goals, for every position (roadmap phase 5).
 *
 * Attack: npxG and xA from Understat, or from SofaScore for players Understat has no record
 * of (npxG = SofaScore xG − penaltyXg × penalties taken).
 * Every expected goal a team creates is shared out, so the credits add up to the
 * team's xG and a chance is never counted twice: the shooter gets $shooterShare, the last
 * passer $passerShare (xA), and the players who built the attack get the rest in proportion
 * to their xGBuildup (including the passer's share when there was no assist).
 * Finishing: (non-penalty goals − npxG) for the shooter's share, shrunk toward zero.
 * Goalkeeping: goals prevented, shrunk toward zero by shots on target faced.
 * Defence: the team's goals prevented against the league average (expected goals on target
 * conceded per 90, from its goalkeepers' data over the minutes they cover, applied to the
 * whole season), shared among outfield players by their share of the team's defensive
 * actions. Team-based, so weak evidence.
 * Finally everything is measured above a replacement level for the role.
 */
final readonly class ImpactModel
{
    /**
     * @param list<string> $defensiveActions fields counted for a player's share of team defending
     */
    public function __construct(
        public float $shooterShare = 0.5,
        public float $passerShare = 0.3,
        public float $buildUpShare = 0.2,
        /** Finishing is shrunk: × shots / (shots + prior). */
        public float $finishingPriorShots = 60.0,
        /** Goals prevented is shrunk: × shots on target faced / (faced + prior). One season is mostly noise. */
        public float $goalkeepingPriorShotsOnTarget = 300.0,
        public array $defensiveActions = ['tackles_won', 'interceptions', 'blocks', 'clearances', 'aerial_duels_won'],
        /** Team goalkeeping data must cover this many minutes for its defence to be measured (as a rate). */
        public float $minTeamGoalkeeperMinutes = 900.0,
        /** Expected-goal value of a penalty: SofaScore's xG includes penalties, Understat's npxG does not. */
        public float $penaltyXg = 0.76,
        /** Replacement level: this percentile of contribution per 90 within the role. */
        public float $replacementPercentile = 0.2,
    ) {
        if (abs($shooterShare + $passerShare + $buildUpShare - 1.0) > 1e-9) {
            throw new \InvalidArgumentException('The shares of an expected goal must add up to 1');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'shooter_share' => $this->shooterShare,
            'passer_share' => $this->passerShare,
            'build_up_share' => $this->buildUpShare,
            'finishing_prior_shots' => $this->finishingPriorShots,
            'goalkeeping_prior_shots_on_target' => $this->goalkeepingPriorShotsOnTarget,
            'defensive_actions' => $this->defensiveActions,
            'min_team_goalkeeper_minutes' => $this->minTeamGoalkeeperMinutes,
            'penalty_xg' => $this->penaltyXg,
            'replacement_percentile' => $this->replacementPercentile,
        ];
    }
}
