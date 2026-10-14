<?php

declare(strict_types=1);

namespace App\Api;

use App\Entity\PlayerCompetitionStats;
use App\Entity\PlayerScore;
use App\Entity\PlayerSeason;
use App\Entity\ScoreRun;
use App\Football\Data\FieldCatalog;
use App\Football\Data\Origin;
use App\Football\Scoring\Impact\ImpactCalculator;

/**
 * Turns domain objects into the JSON the frontend reads. The API is shaped for the
 * screens (ranking, player profile), and every number keeps its provenance.
 */
final class ApiPresenter
{
    /** @return array<string, mixed> */
    public function run(ScoreRun $run): array
    {
        return [
            'id' => $run->getId(),
            'model' => $run->getDefinition(),
            'season' => $run->getSeason()->getLabel(),
            'created_at' => $run->getCreatedAt()->format(\DATE_ATOM),
            'batch_ids' => $run->getBatchIds(),
            'ranked' => $run->getRankedCount(),
            'unranked' => $run->getUnrankedCount(),
        ];
    }

    /** @return array<string, mixed> */
    public function rankingRow(PlayerScore $score): array
    {
        return [
            'rank' => $score->getRank(),
            'score' => self::finalScore($score),
            'confidence' => round($score->getConfidence(), 3),
            'player' => ['id' => $score->getPlayer()->getId(), 'name' => $score->getPlayer()->getName()],
            'team' => $score->getTeam(),
            'position' => $score->getPositionGroup(),
            'minutes' => (int) $score->getMinutes(),
            'components' => array_map(static fn (array $c) => self::round($c['score']), $score->getComponents()),
            // Blended models: the positional part, and the goals contributed above replacement.
            'positional' => $score->getImpact() !== null ? self::round($score->getPositional()) : null,
            'impact_goals' => self::round($score->getImpact()['above'] ?? null),
        ];
    }

    /**
     * Full breakdown: per component and metric, the value, its percentile and its weight,
     * with the labels from the run's own model definition.
     *
     * @return array<string, mixed>
     */
    public function scoreBreakdown(PlayerScore $score): array
    {
        $definition = $score->getRun()->getDefinition();
        $stored = $score->getComponents();
        $components = [];
        // Role models define components per position group; the score was made with his role's.
        $modelComponents = $definition['roles'][$score->getPositionGroup()] ?? $definition['components'];
        foreach ($modelComponents as $component) {
            $result = $stored[$component['key']] ?? null;
            $components[] = [
                'key' => $component['key'],
                'label' => $component['label'],
                'description' => $component['description'],
                'weight' => $component['weight'],
                'score' => self::round($result['score'] ?? null),
                'coverage' => $result['coverage'] ?? 0.0,
                // Stored as 'percentile' since v1; with standard normalisation it is a T-score.
                'metrics' => array_map(static fn (array $metric) => $metric + [
                    'value' => self::round($result['metrics'][$metric['key']]['value'] ?? null, 2),
                    // After the possession factor (if any) and shrinkage toward the role average: what was normalised.
                    'adjusted' => self::round($result['metrics'][$metric['key']]['adjusted'] ?? null, 2),
                    'normalised' => self::round($result['metrics'][$metric['key']]['percentile'] ?? null),
                ] + (isset($result['metrics'][$metric['key']]['possession_factor'])
                    // Possession-adjusted metrics only: the multiplier for the team's possession.
                    ? ['possession_factor' => self::round($result['metrics'][$metric['key']]['possession_factor'], 3)]
                    : []), $component['metrics']),
            ];
        }

        return [
            'run_id' => $score->getRun()->getId(),
            'model_id' => $score->getRun()->getModelId(),
            'rank' => $score->getRank(),
            'ranked_players' => $score->getRun()->getRankedCount(),
            'score' => self::finalScore($score),
            'composite' => self::round($score->getComposite()),
            'role' => isset($definition['roles']) ? $score->getPositionGroup() : null,
            'normalization' => $definition['normalization'] ?? 'percentile',
            'confidence' => round($score->getConfidence(), 3),
            'components' => $components,
            'positional' => $score->getImpact() !== null ? self::round($score->getPositional()) : null,
            'impact' => self::impact($score),
        ];
    }

    /**
     * Contribution in goals per component, with how strong the evidence for each is.
     *
     * @return array<string, mixed>|null
     */
    private static function impact(PlayerScore $score): ?array
    {
        $impact = $score->getImpact();
        if ($impact === null) {
            return null;
        }

        $calibration = $impact['calibration'] ?? null;

        return [
            'components' => array_map(static fn (string $key) => [
                'key' => $key,
                'goals' => self::round($impact['goals'][$key] ?? null, 2),
                // Build-up estimated from passes (SofaScore fallback) is weaker evidence than xGBuildup.
                'evidence' => $key === 'build_up' && $calibration !== null ? 'weak' : ImpactCalculator::EVIDENCE[$key],
            ], array_values(array_filter(
                ImpactCalculator::COMPONENTS, // fixed order (jsonb sorts keys)
                static fn (string $key) => array_key_exists($key, $impact['goals']), // only the model's own parts
            ))),
            'total' => self::round($impact['total'], 2),
            'replacement' => self::round($impact['replacement'], 2),
            'above' => self::round($impact['above'], 2),
            // Understat, or SofaScore when Understat has no record of the player.
            'attack_source' => $impact['attack_source'] ?? null,
            // SofaScore fallback: scale factors to Understat's level, xGBuildup per accurate pass.
            'calibration' => $calibration === null ? null : [
                'xg' => self::round($calibration['xg'], 3),
                'xa' => self::round($calibration['xa'], 3),
                'build_up_per_pass' => self::round($calibration['build_up_per_pass'], 5),
            ],
            'score' => self::round($impact['score']),
            'weight' => $impact['weight'],
        ];
    }

    /** @return array<string, mixed> */
    public function season(PlayerSeason $season): array
    {
        return [
            'label' => $season->getSeason()->getLabel(),
            'positions' => $season->getPositions(),
            'position_group' => $season->getPositionGroup(),
            'team' => $season->getMainTeam()?->getName(),
            'stints' => $season->getStints()->map(static fn ($stint) => [
                'team' => $stint->getTeam()->getName(),
                'competition' => ['code' => $stint->getCompetition()->getCode(), 'name' => $stint->getCompetition()->getName()],
                'appearances' => $stint->getAppearances(),
                'starts' => $stint->getStarts(),
                'minutes' => $stint->getMinutes(),
            ])->getValues(),
        ];
    }

    /**
     * Every catalog field for this scope: value, per 90, which provider supplied it and
     * how far that provider can be trusted for this player.
     *
     * @return array<string, mixed>
     */
    public function stats(PlayerCompetitionStats $stats, bool $goalkeeper): array
    {
        $fields = [];
        foreach (FieldCatalog::all() as $key => $field) {
            if ($field->goalkeeper && !$goalkeeper) {
                continue;
            }
            $value = $stats->value($key);
            $fields[] = [
                'key' => $key,
                'label' => $field->label,
                'category' => $field->category,
                'value' => self::round($value, 2),
                'per90' => self::round($stats->per90($key), 2),
                'origin' => $stats->origin($key)?->value,
                'status' => $value !== null ? $stats->fieldStatus($key)->value : null,
            ];
        }

        $providers = [];
        foreach ($stats->getOriginChecks() as $origin => $check) {
            $providers[] = ['origin' => Origin::from($origin)->value] + $check;
        }

        return [
            'minutes' => (int) $stats->getMinutes(),
            'batch' => ['id' => $stats->getBatch()->getId(), 'source' => $stats->getBatch()->getSource(), 'imported_at' => $stats->getBatch()->getImportedAt()->format(\DATE_ATOM)],
            'providers' => $providers,
            'fields' => $fields,
        ];
    }

    /**
     * Unranked players get no final score: built on a small part of the model, it is not
     * comparable with the ranked players' scores (their components are still shown).
     */
    private static function finalScore(PlayerScore $score): ?float
    {
        return $score->getRank() === null ? null : self::round($score->getScore());
    }

    private static function round(?float $value, int $precision = 1): ?float
    {
        return $value === null ? null : round($value, $precision);
    }
}
