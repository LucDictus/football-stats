<?php

declare(strict_types=1);

namespace App\DataImport\Normalizer;

use App\Entity\PlayerCompetitionStats;
use App\Football\Data\RoleCatalog;

/**
 * Infers the role of a player the EA SPORTS FC data has no position for, from what he does:
 * the role whose typical profile (the average of the labelled players in that role) is
 * nearest to his own, among the roles compatible with his source position group. Pure and
 * deterministic.
 *
 * The profile is a set of per-90 statistics, on a square-root scale (counts are skewed) and
 * standardised over the labelled players. How often this gets a labelled player's role right
 * is measured by leaving each one out in turn (accuracy()).
 */
final class RoleInference
{
    /** Per-90 statistics that tell roles apart. */
    public const FEATURES = [
        'tackles_won', 'interceptions', 'clearances', 'aerial_duels_won', 'ball_recoveries',
        'possession_won_att_third', 'accurate_passes', 'key_passes', 'big_chances_created',
        'accurate_crosses', 'successful_dribbles', 'shots',
    ];

    /** Role when the statistics cannot tell (too few minutes): the most common role of the group. */
    public const DEFAULT_ROLES = ['FW' => 'ST', 'MF' => 'CM', 'DF' => 'CB'];

    /** @var array<string, list<float>> role => centroid */
    private array $centroids = [];

    /** @var array<string, int> */
    private array $counts = [];

    /** @var array<string, list<float>> role => sum of profiles */
    private array $sums = [];

    /** @var array<int, float> feature index => mean over the labelled players */
    private array $mean = [];

    /** @var array<int, float> feature index => standard deviation */
    private array $sd = [];

    /**
     * @param list<array{role: string, stats: PlayerCompetitionStats}> $labelled outfield players with an EA FC role
     */
    public function __construct(array $labelled)
    {
        $raw = [];
        foreach ($labelled as $player) {
            $profile = self::raw($player['stats']);
            if ($profile !== null) {
                $raw[] = ['role' => $player['role'], 'profile' => $profile];
            }
        }
        if ($raw === []) {
            return;
        }

        foreach (array_keys(self::FEATURES) as $f) {
            $values = array_map(static fn (array $p) => $p['profile'][$f], $raw);
            $mean = array_sum($values) / count($values);
            $this->mean[$f] = $mean;
            $this->sd[$f] = sqrt(array_sum(array_map(static fn (float $v) => ($v - $mean) ** 2, $values)) / count($values)) ?: 1.0;
        }
        foreach ($raw as $p) {
            $z = $this->standardise($p['profile']);
            $this->sums[$p['role']] = isset($this->sums[$p['role']]) ? array_map(static fn (float $a, float $b) => $a + $b, $this->sums[$p['role']], $z) : $z;
            $this->counts[$p['role']] = ($this->counts[$p['role']] ?? 0) + 1;
        }
        foreach ($this->sums as $role => $sum) {
            $this->centroids[$role] = array_map(fn (float $v) => $v / $this->counts[$role], $sum);
        }
    }

    /**
     * The nearest compatible role, or the group's default when the statistics cannot tell.
     *
     * @return array{0: string, 1: string}|null role and source (RoleCatalog::SOURCE_INFERRED or SOURCE_DEFAULT)
     */
    public function infer(?string $group, ?PlayerCompetitionStats $stats): ?array
    {
        $default = isset(self::DEFAULT_ROLES[$group]) ? [self::DEFAULT_ROLES[$group], RoleCatalog::SOURCE_DEFAULT] : null;
        $profile = $stats !== null ? self::raw($stats) : null;
        if ($group === null || $profile === null || $this->centroids === []) {
            return $default;
        }
        $role = $this->nearest($this->standardise($profile), RoleCatalog::rolesFor($group), null);

        return $role !== null ? [$role, RoleCatalog::SOURCE_INFERRED] : $default;
    }

    /**
     * Share of labelled players whose role the inference gets right when they are left out of
     * the typical profiles, among players with at least $minMinutes and a compatible group.
     *
     * @param list<array{role: string, group: ?string, stats: PlayerCompetitionStats}> $players
     *
     * @return array{evaluated: int, correct: int}
     */
    public function accuracy(array $players, float $minMinutes): array
    {
        $evaluated = 0;
        $correct = 0;
        foreach ($players as $player) {
            $profile = self::raw($player['stats']);
            if ($profile === null || $player['group'] === null || $player['stats']->getMinutes() < $minMinutes || !isset($this->counts[$player['role']])) {
                continue;
            }
            $roles = RoleCatalog::rolesFor($player['group']);
            if (!in_array($player['role'], $roles, true)) {
                continue;
            }
            ++$evaluated;
            $correct += (int) ($this->nearest($this->standardise($profile), $roles, $player['role']) === $player['role']);
        }

        return ['evaluated' => $evaluated, 'correct' => $correct];
    }

    /**
     * @param list<float>  $z
     * @param list<string> $roles
     * @param string|null  $leaveOut role whose centroid leaves this profile out
     */
    private function nearest(array $z, array $roles, ?string $leaveOut): ?string
    {
        $best = null;
        $bestDistance = INF;
        foreach ($roles as $role) {
            if (!isset($this->centroids[$role])) {
                continue;
            }
            $centroid = $this->centroids[$role];
            if ($role === $leaveOut) {
                if ($this->counts[$role] < 2) {
                    continue;
                }
                $centroid = array_map(fn (float $sum, float $own) => ($sum - $own) / ($this->counts[$role] - 1), $this->sums[$role], $z);
            }
            $distance = array_sum(array_map(static fn (float $a, float $b) => ($a - $b) ** 2, $z, $centroid));
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $role;
            }
        }

        return $best;
    }

    /**
     * @param list<float> $profile
     *
     * @return list<float>
     */
    private function standardise(array $profile): array
    {
        return array_map(fn (int $f) => ($profile[$f] - $this->mean[$f]) / $this->sd[$f], array_keys($profile));
    }

    /** @return list<float>|null square roots of the per-90 statistics; null when one is unknown */
    private static function raw(PlayerCompetitionStats $stats): ?array
    {
        $profile = [];
        foreach (self::FEATURES as $field) {
            $value = $stats->per90($field);
            if ($value === null) {
                return null;
            }
            $profile[] = sqrt(max(0.0, $value));
        }

        return $profile;
    }
}
