<?php

declare(strict_types=1);

namespace App\Football\Scoring;

use App\Entity\StatsScope;

/** All released scoring models. Add a new version; never edit a released one. */
final class ModelCatalog
{
    public const DEFAULT = 'baseline-v1';

    /** @return array<string, ScoringModel> model id => model */
    public static function all(): array
    {
        $models = [self::baselineV1()];

        return array_combine(array_map(static fn (ScoringModel $m) => $m->id(), $models), $models);
    }

    public static function get(string $id): ScoringModel
    {
        return self::all()[$id] ?? throw new \InvalidArgumentException(sprintf('Unknown model %s. Known: %s', $id, implode(', ', array_keys(self::all()))));
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
