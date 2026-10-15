<?php

declare(strict_types=1);

namespace App\Football\Scoring;

use App\Entity\StatsScope;
use App\Football\Data\RoleCatalog;
use App\Football\Scoring\Impact\ImpactModel;
use App\Football\Standards\StandardsFile;
use App\Football\Standards\Statistic;
use App\Football\Standards\StatisticCatalog;

/** All released scoring models. Add a new version; never edit a released one. */
final class ModelCatalog
{
    public const DEFAULT = 'mvp-v3';

    /** @return array<string, ScoringModel> model id => model */
    public static function all(): array
    {
        // Released (hashes pinned in ScoreCalculatorTest): baseline-v1, positional-v1, positional-v2, mvp-v1, mvp-v2.
        // Release candidate: mvp-v3.
        $models = [self::baselineV1(), self::positionalV1(), self::positionalV2(), self::mvpV1(), self::mvpV2(), self::mvpV3()];

        return array_combine(array_map(static fn (ScoringModel $m) => $m->id(), $models), $models);
    }

    public static function get(string $id): ScoringModel
    {
        return self::all()[$id] ?? throw new \InvalidArgumentException(sprintf('Unknown model %s. Known: %s', $id, implode(', ', array_keys(self::all()))));
    }

    /**
     * Roadmap phase 5, finer roles and data-derived standards: every player is compared with his
     * own role (RoleCatalog: from EA SPORTS FC positions, or inferred from his statistics) and
     * replaced by a replacement-level player of that role. The positional half no longer uses
     * chosen components and weights: per role, the statistics and their weights are derived
     * from two seasons of data (StandardsDeriver, docs/standards.md). Contribution as in mvp-v2.
     */
    private static function mvpV3(): ScoringModel
    {
        $v2 = self::mvpV2();

        return new ScoringModel(
            key: 'mvp',
            version: '3',
            label: 'MVP v3',
            description: 'Half positional excellence (how far a player stands above others in his role: striker, winger, attacking, central or defensive midfielder, full-back, centre-back, goalkeeper, on the statistics that the data shows go with winning in that role), half contribution: the goals he added above a replacement-level player in his role.',
            scope: $v2->scope,
            minMinutes: $v2->minMinutes,
            positionGroups: array_keys(RoleCatalog::ROLES),
            minConfidence: $v2->minConfidence,
            components: [],
            roles: self::standardRoles('standards-v1'),
            normalization: $v2->normalization,
            referenceRankedOnly: $v2->referenceRankedOnly,
            impact: $v2->impact,
            impactWeight: $v2->impactWeight,
            byRole: true,
            standards: 'standards-v1',
        );
    }

    /**
     * The positional half per role, as derived from the data (StandardsDeriver, frozen in
     * config/standards/): each measure a component weighted by its link with winning × its
     * repeatability; each statistic in it per 90 (square-root scale) or as a success rate,
     * pulled toward the role average by its data-derived prior and adjusted for possession.
     *
     * @return array<string, non-empty-list<Component>>
     */
    private static function standardRoles(string $id): array
    {
        $standards = StandardsFile::read(dirname(__DIR__, 3) . "/config/standards/{$id}.json");
        $roles = [];
        foreach ($standards['roles'] as $role => $standard) {
            $total = array_sum(array_column($standard['measures'], 'weight'));
            $components = [];
            foreach ($standard['measures'] as $measure) {
                $metrics = array_map(static function (string $key) use ($standard, $measure) {
                    $statistic = StatisticCatalog::get($key);
                    $s = $standard['statistics'][$key];
                    $rate = $statistic->kind === Statistic::RATE;

                    return new Metric(
                        $key,
                        $statistic->label,
                        $statistic->fields,
                        $rate ? MetricMode::Ratio : MetricMode::Per90,
                        1 / count($measure['members']),
                        lowerIsBetter: $statistic->direction < 0,
                        shrinkage: (float) $s['prior'],
                        sampleFields: $statistic->attempts,
                        sqrt: !$rate,
                        possessionSlope: (float) $s['slope'],
                        possessionMean: (float) $s['possession_mean'],
                        proxy: $s['proxy_scale'] !== null ? $statistic->proxy : [],
                        proxyScale: $s['proxy_scale'],
                    );
                }, $measure['members']);
                if ($metrics === []) {
                    continue;
                }
                $components[] = new Component(
                    $measure['key'],
                    $measure['label'],
                    sprintf(
                        'Goes with winning (r %.2f, possession taken into account) and repeats from season to season (r %.2f).',
                        $measure['relevance'],
                        $measure['reliability'],
                    ),
                    $measure['weight'] / $total,
                    $metrics,
                );
            }
            if ($components !== []) {
                $roles[(string) $role] = $components;
            }
        }

        return $roles;
    }

    /**
     * Roadmap phase 5, after the overlap check (docs/overlap-mvp-v1.md) and on defending:
     * - goalkeepers: positional shot stopping shrunk by shots on target faced like the
     *   contribution's goalkeeping, so one noisy statistic is not let through at full strength
     *   in one half while it is shrunk hard in the other;
     * - defensive counts per 90 adjusted for team possession, with each statistic's
     *   sensitivity fitted from the data (PossessionAdjustment);
     * - errors leading to a goal cost an outfield player a goal each in the contribution
     *   (shrunk toward the role's rate, like other rare events), instead of a positional
     *   reliability component for defenders only;
     * - goalkeepers: distribution added, so goals prevented carries less of the positional half.
     */
    private static function mvpV2(): ScoringModel
    {
        $v1 = self::mvpV1();

        return new ScoringModel(
            key: 'mvp',
            version: '2',
            label: 'MVP v2',
            description: 'Half positional excellence (how far a player stands above others in his position, with defending adjusted for team possession), half contribution: the goals he added above a replacement-level player, measured the same way for every position.',
            scope: $v1->scope,
            minMinutes: $v1->minMinutes,
            positionGroups: $v1->positionGroups,
            minConfidence: $v1->minConfidence,
            components: [],
            roles: self::positionalRoles(v3: true),
            normalization: $v1->normalization,
            referenceRankedOnly: $v1->referenceRankedOnly,
            impact: new ImpactModel(errorGoals: 1.0),
            impactWeight: $v1->impactWeight,
        );
    }

    /**
     * Roadmap phase 5: half how far a player stands above his positional peers (positional-v2,
     * unchanged), half his contribution in goals above replacement (ImpactModel), measured in
     * the same unit for every position. Positional excellence alone favoured midfielders: the
     * biggest and most varied group produces the most extreme scores (docs/methodology.md).
     */
    private static function mvpV1(): ScoringModel
    {
        $positional = self::positionalV2();

        return new ScoringModel(
            key: 'mvp',
            version: '1',
            label: 'MVP v1',
            description: 'Half positional excellence (how far a player stands above others in his position), half contribution: the goals he added above a replacement-level player, measured the same way for every position.',
            scope: $positional->scope,
            minMinutes: $positional->minMinutes,
            positionGroups: $positional->positionGroups,
            minConfidence: $positional->minConfidence,
            components: [],
            roles: $positional->roles,
            normalization: $positional->normalization,
            referenceRankedOnly: $positional->referenceRankedOnly,
            impact: new ImpactModel(),
            impactWeight: 0.5,
        );
    }

    /**
     * Phase 4, after review: positional-v1 let skewed minor metrics and tiny samples drive the
     * ranking (a full-back's 1,256 attacking minutes made him the best defender; 15 out of 15
     * tackles scored as a perfect season). Changes:
     * - per-90 rates and success rates are shrunk toward the role average (empirical Bayes,
     *   a prior worth 10 matches or 40 attempts): little evidence, little deviation;
     * - per-90 counts are standardised on a square-root scale (the variance-stabilising
     *   transform for counts), so a few extreme values in a skewed metric do not dominate,
     *   without capping the top (a ±3 cap was tried and flattened the elite: docs/methodology.md);
     * - no season totals: volume is measured once, by minutes (totals = rate × minutes would
     *   count minutes again);
     * - "average" is defined by the ranked players only, so scores built on very little data
     *   do not stretch the scale.
     */
    private static function positionalV2(): ScoringModel
    {
        return new ScoringModel(
            key: 'positional',
            version: '2',
            label: 'Positional v2',
            description: 'Each position measured on what its role is about and compared only with players in the same position (900+ league minutes): the score says how far a player stands above his positional peers, so all positions share one ranking. Rates on small samples are pulled toward the average.',
            scope: StatsScope::League,
            minMinutes: 900,
            positionGroups: ['FW', 'MF', 'DF', 'GK'],
            minConfidence: 0.6,
            components: [],
            roles: self::positionalRoles(),
            normalization: Normalization::Standard,
            referenceRankedOnly: true,
        );
    }

    /**
     * The roles of positional-v2, or with $v3 the positional half of mvp-v2: defensive counts
     * (and goalkeepers' passing) adjusted for team possession, errors moved to the
     * contribution, and distribution for goalkeepers.
     *
     * @return array<string, non-empty-list<Component>>
     */
    private static function positionalRoles(bool $v3 = false): array
    {
        $availability = static fn (float $weight = 0.2) => new Component('availability', 'Availability', 'League minutes played: the volume of his season. Every other metric is a rate per 90.', $weight, [
            new Metric('minutes', 'League minutes', ['minutes'], MetricMode::Total, 1.0),
        ]);
        $reliability = static fn (float $weight) => new Component('reliability', 'Reliability', 'Errors leading to a goal: fewer is better.', $weight, [
            self::shrunkPer90('errors_p90', 'Errors leading to goal per 90', ['errors_leading_to_goal'], 1.0, lowerIsBetter: true, sqrt: false),
        ]);
        $buildUp = static fn (float $weight) => new Component('build_up', 'Build-up', 'Moving the ball forward and keeping it: xGBuildup, final-third passes and accurate passes.', $weight, [
            self::shrunkPer90('xg_buildup_p90', 'xGBuildup per 90', ['xg_buildup'], 0.4),
            self::shrunkPer90('final_third_passes_p90', 'Accurate final-third passes per 90', ['final_third_passes'], 0.3),
            self::shrunkPer90('accurate_passes_p90', 'Accurate passes per 90', ['accurate_passes'], 0.3),
        ]);

        $adjusted = static fn (string $key, string $label, string $field, float $weight) => new Metric(
            $key, $label, [$field], MetricMode::Per90, $weight, shrinkage: 10.0, sqrt: true, possessionAdjusted: $v3,
        );
        $possession = $v3 ? ' Adjusted for team possession: how much each depends on it is measured from the data.' : '';
        $shotStopping = self::shrunkPer90('goals_prevented_p90', 'Goals prevented per 90', ['goals_prevented'], 1.0, sqrt: false);

        return [
            'FW' => [
                new Component('attacking_output', 'Attacking output', 'Non-penalty goals plus assists per 90.', 0.4, [
                    self::shrunkPer90('np_ga_p90', 'Non-penalty goals + assists per 90', ['np_goals', 'assists'], 1.0),
                ]),
                new Component('attacking_quality', 'Attacking quality', 'Non-penalty xG plus xA per 90: the chances he got and created, independent of finishing luck.', 0.3, [
                    self::shrunkPer90('npxg_xa_p90', 'Non-penalty xG + xA per 90', ['np_xg', 'xa'], 1.0),
                ]),
                new Component('link_up', 'Link-up play', 'Big chances created and involvement in build-up (xGBuildup excludes his own shots and key passes).', 0.1, [
                    self::shrunkPer90('big_chances_p90', 'Big chances created per 90', ['big_chances_created'], 0.5),
                    self::shrunkPer90('xg_buildup_p90', 'xGBuildup per 90', ['xg_buildup'], 0.5),
                ]),
                $availability(),
            ],
            'MF' => [
                new Component('creation', 'Chance creation', 'Passes that lead to shots, big chances and their expected-assist value.', 0.25, [
                    self::shrunkPer90('key_passes_p90', 'Key passes per 90', ['key_passes'], 0.4),
                    self::shrunkPer90('big_chances_p90', 'Big chances created per 90', ['big_chances_created'], 0.3),
                    self::shrunkPer90('xa_p90', 'xA per 90', ['xa'], 0.3),
                ]),
                $buildUp(0.25),
                new Component('attacking_output', 'Attacking output', 'Non-penalty goals plus assists per 90.', 0.15, [
                    self::shrunkPer90('np_ga_p90', 'Non-penalty goals + assists per 90', ['np_goals', 'assists'], 1.0),
                ]),
                new Component('defending', 'Defending', 'Winning the ball back: tackles won, interceptions and recoveries.' . $possession, 0.15, [
                    $adjusted('tackles_won_p90', 'Tackles won per 90', 'tackles_won', 0.4),
                    $adjusted('interceptions_p90', 'Interceptions per 90', 'interceptions', 0.3),
                    $adjusted('recoveries_p90', 'Ball recoveries per 90', 'ball_recoveries', 0.3),
                ]),
                $availability(),
            ],
            'DF' => [
                new Component('defending', 'Defensive actions', 'Tackles won, interceptions, aerial duels won, blocks, recoveries and clearances per 90.' . $possession, $v3 ? 0.25 : 0.2, [
                    $adjusted('tackles_won_p90', 'Tackles won per 90', 'tackles_won', 0.2),
                    $adjusted('interceptions_p90', 'Interceptions per 90', 'interceptions', 0.2),
                    $adjusted('aerials_won_p90', 'Aerial duels won per 90', 'aerial_duels_won', 0.2),
                    $adjusted('blocks_p90', 'Blocks per 90', 'blocks', 0.15),
                    $adjusted('recoveries_p90', 'Ball recoveries per 90', 'ball_recoveries', 0.15),
                    $adjusted('clearances_p90', 'Clearances per 90', 'clearances', 0.1),
                ]),
                new Component('duels', 'Duel success', 'How often his aerial duels and tackles succeed: quality, not volume. Few duels are pulled toward the average.', $v3 ? 0.25 : 0.2, [
                    self::shrunkRate('aerial_win_pct', 'Aerial duels won %', 'aerial_win_pct', ['aerial_duels_won', 'aerial_duels_lost'], 0.6),
                    self::shrunkRate('tackle_success_pct', 'Tackles won %', 'tackle_success_pct', ['tackles'], 0.4),
                ]),
                // v3: errors leading to a goal count in the contribution (a goal each), not twice.
                ...($v3 ? [] : [$reliability(0.1)]),
                $buildUp(0.2),
                new Component('attacking', 'Attacking threat', 'Goals and assists, and the expected value of his chances, per 90.', 0.1, [
                    self::shrunkPer90('np_ga_p90', 'Non-penalty goals + assists per 90', ['np_goals', 'assists'], 0.5),
                    self::shrunkPer90('npxg_xa_p90', 'Non-penalty xG + xA per 90', ['np_xg', 'xa'], 0.5),
                ]),
                $availability(),
            ],
            'GK' => [
                new Component('shot_stopping', 'Shot stopping', 'Goals prevented per 90: goals the shots he faced were expected to produce, minus the goals he conceded.', $v3 ? 0.5 : 0.7, [
                    $shotStopping,
                ]),
                // v3: distribution adds what goals prevented (also the contribution's goalkeeping) does not.
                ...($v3 ? [new Component('distribution', 'Distribution', 'Accurate passes per 90: starting attacks from the back. Adjusted for team possession, which drives how much a goalkeeper gets to pass.', 0.15, [
                    $adjusted('accurate_passes_p90', 'Accurate passes per 90', 'accurate_passes', 1.0),
                ])] : []),
                $reliability(0.1),
                $availability($v3 ? 0.25 : 0.2),
            ],
        ];
    }

    /**
     * A per-90 count shrunk toward the group average with a prior worth 10 matches, standardised
     * on a square-root scale. Not for metrics that can be negative (goals prevented) or that are
     * mostly zero where a single event should not widen the gap (errors): pass $sqrt false.
     *
     * @param non-empty-list<string> $fields
     */
    private static function shrunkPer90(string $key, string $label, array $fields, float $weight, bool $lowerIsBetter = false, bool $sqrt = true): Metric
    {
        return new Metric($key, $label, $fields, MetricMode::Per90, $weight, $lowerIsBetter, shrinkage: 10.0, sqrt: $sqrt);
    }

    /**
     * A success rate shrunk toward the group average with a prior worth 40 attempts: success
     * rates are noisy (at 60% over 40 attempts the binomial spread is ±7.7 points, about the
     * real spread between players).
     *
     * @param non-empty-list<string> $sampleFields fields whose sum is the number of attempts
     */
    private static function shrunkRate(string $key, string $label, string $field, array $sampleFields, float $weight): Metric
    {
        return new Metric($key, $label, [$field], MetricMode::Rate, $weight, shrinkage: 40.0, sampleFields: $sampleFields);
    }

    /**
     * Roadmap phase 4: every position group measured on what its role is about, and compared
     * only with its own group. A 90 means exceptional for that role, so one ranking can hold
     * strikers, midfielders, defenders and goalkeepers.
     *
     * Overlap is avoided (roadmap 5.2): xGBuildup measures build-up without the player's own
     * shots and key passes, which are counted elsewhere; save % is left out because goals
     * prevented already measures shot stopping. Defensive action counts are not adjusted for
     * team possession yet (no possession data).
     */
    private static function positionalV1(): ScoringModel
    {
        $p90 = MetricMode::Per90;
        $total = MetricMode::Total;
        $availability = new Component('availability', 'Availability', 'League minutes played: a great season needs to be a full season.', 0.15, [
            new Metric('minutes', 'League minutes', ['minutes'], $total, 1.0),
        ]);
        $output = static fn (float $weight) => new Component('attacking_output', 'Attacking output', 'Non-penalty goals plus assists.', $weight, [
            new Metric('np_ga_p90', 'Non-penalty goals + assists per 90', ['np_goals', 'assists'], $p90, 0.5),
            new Metric('np_ga', 'Non-penalty goals + assists', ['np_goals', 'assists'], $total, 0.5),
        ]);
        $reliability = static fn (float $weight) => new Component('reliability', 'Reliability', 'Errors leading to a goal: fewer is better.', $weight, [
            new Metric('errors_p90', 'Errors leading to goal per 90', ['errors_leading_to_goal'], $p90, 1.0, lowerIsBetter: true),
        ]);

        return new ScoringModel(
            key: 'positional',
            version: '1',
            label: 'Positional v1',
            description: 'Each position measured on what its role is about and compared only with players in the same position (900+ league minutes): the score says how far a player stands above his positional peers, so all positions share one ranking.',
            scope: StatsScope::League,
            minMinutes: 900,
            positionGroups: ['FW', 'MF', 'DF', 'GK'],
            minConfidence: 0.6,
            components: [],
            // Standard scores keep distances: a runaway leader stands out. Chosen over
            // percentiles after comparing both on the 2025/26 data (docs/methodology.md).
            normalization: Normalization::Standard,
            roles: [
                'FW' => [
                    $output(0.4),
                    new Component('attacking_quality', 'Attacking quality', 'Non-penalty xG plus xA: the chances he got and created, independent of finishing luck.', 0.3, [
                        new Metric('npxg_xa_p90', 'Non-penalty xG + xA per 90', ['np_xg', 'xa'], $p90, 0.5),
                        new Metric('npxg_xa', 'Non-penalty xG + xA', ['np_xg', 'xa'], $total, 0.5),
                    ]),
                    new Component('link_up', 'Link-up play', 'Big chances created and involvement in build-up (xGBuildup excludes his own shots and key passes).', 0.15, [
                        new Metric('big_chances_p90', 'Big chances created per 90', ['big_chances_created'], $p90, 0.5),
                        new Metric('xg_buildup_p90', 'xGBuildup per 90', ['xg_buildup'], $p90, 0.5),
                    ]),
                    $availability,
                ],
                'MF' => [
                    new Component('creation', 'Chance creation', 'Passes that lead to shots, big chances and their expected-assist value.', 0.3, [
                        new Metric('key_passes_p90', 'Key passes per 90', ['key_passes'], $p90, 0.4),
                        new Metric('big_chances_p90', 'Big chances created per 90', ['big_chances_created'], $p90, 0.3),
                        new Metric('xa_p90', 'xA per 90', ['xa'], $p90, 0.3),
                    ]),
                    new Component('build_up', 'Build-up', 'Moving the ball forward and keeping it: xGBuildup, final-third passes and accurate passes.', 0.25, [
                        new Metric('xg_buildup_p90', 'xGBuildup per 90', ['xg_buildup'], $p90, 0.4),
                        new Metric('final_third_passes_p90', 'Accurate final-third passes per 90', ['final_third_passes'], $p90, 0.35),
                        new Metric('accurate_passes_p90', 'Accurate passes per 90', ['accurate_passes'], $p90, 0.25),
                    ]),
                    $output(0.2),
                    new Component('defending', 'Defending', 'Winning the ball back: tackles won, interceptions and recoveries.', 0.1, [
                        new Metric('tackles_won_p90', 'Tackles won per 90', ['tackles_won'], $p90, 0.4),
                        new Metric('interceptions_p90', 'Interceptions per 90', ['interceptions'], $p90, 0.3),
                        new Metric('recoveries_p90', 'Ball recoveries per 90', ['ball_recoveries'], $p90, 0.3),
                    ]),
                    $availability,
                ],
                'DF' => [
                    new Component('defending', 'Defensive actions', 'Tackles won, interceptions, aerial duels won, blocks, recoveries and clearances per 90.', 0.25, [
                        new Metric('tackles_won_p90', 'Tackles won per 90', ['tackles_won'], $p90, 0.2),
                        new Metric('interceptions_p90', 'Interceptions per 90', ['interceptions'], $p90, 0.2),
                        new Metric('aerials_won_p90', 'Aerial duels won per 90', ['aerial_duels_won'], $p90, 0.2),
                        new Metric('blocks_p90', 'Blocks per 90', ['blocks'], $p90, 0.15),
                        new Metric('recoveries_p90', 'Ball recoveries per 90', ['ball_recoveries'], $p90, 0.15),
                        new Metric('clearances_p90', 'Clearances per 90', ['clearances'], $p90, 0.1),
                    ]),
                    new Component('duels', 'Duel success', 'How often he wins his aerial duels and tackles: quality, not volume, so playing for a team that rarely defends does not count against him.', 0.2, [
                        new Metric('aerial_win_pct', 'Aerial duels won %', ['aerial_win_pct'], MetricMode::Rate, 0.6),
                        new Metric('tackle_success_pct', 'Tackle success %', ['tackle_success_pct'], MetricMode::Rate, 0.4),
                    ]),
                    $reliability(0.1),
                    new Component('build_up', 'Build-up', 'Moving the ball forward and keeping it: xGBuildup, accurate passes and final-third passes.', 0.2, [
                        new Metric('xg_buildup_p90', 'xGBuildup per 90', ['xg_buildup'], $p90, 0.4),
                        new Metric('accurate_passes_p90', 'Accurate passes per 90', ['accurate_passes'], $p90, 0.3),
                        new Metric('final_third_passes_p90', 'Accurate final-third passes per 90', ['final_third_passes'], $p90, 0.3),
                    ]),
                    new Component('attacking', 'Attacking threat', 'Goals and assists, and the expected value of his chances.', 0.1, [
                        new Metric('np_ga_p90', 'Non-penalty goals + assists per 90', ['np_goals', 'assists'], $p90, 0.5),
                        new Metric('npxg_xa_p90', 'Non-penalty xG + xA per 90', ['np_xg', 'xa'], $p90, 0.5),
                    ]),
                    $availability,
                ],
                'GK' => [
                    new Component('shot_stopping', 'Shot stopping', 'Goals prevented: goals the shots he faced were expected to produce, minus the goals he conceded.', 0.7, [
                        new Metric('goals_prevented', 'Goals prevented', ['goals_prevented'], $total, 0.5),
                        new Metric('goals_prevented_p90', 'Goals prevented per 90', ['goals_prevented'], $p90, 0.5),
                    ]),
                    $reliability(0.15),
                    $availability,
                ],
            ],
        );
    }

    /**
     * Roadmap phase 3: a deliberately simple baseline. Domestic league only, outfield players
     * only (goalkeepers need their own metrics, phase 4). Volume (season totals) and
     * efficiency (per 90) are kept as separate metrics within each attacking component.
     * A player must be measurable on 60% of the model to be ranked: with only one attacking
     * component missing that is still the case; with both missing, minutes alone would rank him.
     */
    private static function baselineV1(): ScoringModel
    {
        return new ScoringModel(
            key: 'baseline',
            version: '1',
            label: 'Baseline v1',
            description: 'Attacking output, attacking quality and availability in the domestic league, as percentiles among outfield players with 900+ league minutes.',
            scope: StatsScope::League,
            minMinutes: 900,
            positionGroups: ['DF', 'MF', 'FW'],
            minConfidence: 0.6,
            components: [
                new Component('attacking_output', 'Attacking output', 'Non-penalty goals plus assists: what the player actually produced.', 0.4, [
                    new Metric('np_ga_p90', 'Non-penalty goals + assists per 90', ['np_goals', 'assists'], MetricMode::Per90, 0.5),
                    new Metric('np_ga', 'Non-penalty goals + assists', ['np_goals', 'assists'], MetricMode::Total, 0.5),
                ]),
                new Component('attacking_quality', 'Attacking quality', 'Non-penalty xG plus xA: the quality of the chances the player got and created, independent of finishing luck.', 0.4, [
                    new Metric('npxg_xa_p90', 'Non-penalty xG + xA per 90', ['np_xg', 'xa'], MetricMode::Per90, 0.5),
                    new Metric('npxg_xa', 'Non-penalty xG + xA', ['np_xg', 'xa'], MetricMode::Total, 0.5),
                ]),
                new Component('availability', 'Availability', 'League minutes played: a great season needs to be a full season.', 0.2, [
                    new Metric('minutes', 'League minutes', ['minutes'], MetricMode::Total, 1.0),
                ]),
            ],
        );
    }
}
