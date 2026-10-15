<?php

declare(strict_types=1);

namespace App\Football\Standards;

/**
 * Derives the positional standards from the data: per role, which statistics count and how
 * much. Pure and deterministic. Every step is a measurement; the conventions it rests on are
 * the constants below (statistical conventions, the same for every role and statistic).
 *
 * Per role, over the players with 900+ league minutes in two seasons:
 *
 * 1. Each candidate statistic (StatisticCatalog) is measured per 90 minutes or as a success
 *    rate, pulled toward the role average as far as its sample is small (empirical Bayes; the
 *    prior follows from how well the raw statistic repeats between seasons), put on a square-root scale if it is a count (the variance-stabilising transform
 *    for counts), and adjusted for the team's possession (the part that a linear fit on
 *    possession explains is removed: a team with the ball passes more, one without defends more).
 * 2. Link with winning: its correlation with the team's goal difference per match (also
 *    adjusted for possession), with failures counted negatively. A statistic is used only when
 *    the link is positive and significant (one-sided 5%) in each season on its own: a link
 *    that does not replicate is likely chance. Teammates share their team's results, so the
 *    test uses the effective number of players (design effect of the statistic's clustering
 *    within teams), not the raw count.
 * 3. Repeatability: its correlation between the two seasons for the same players in the same
 *    role. What repeats measures the player; what does not is mostly luck.
 * 4. Statistics that measure the same thing (correlation 0.8 or more, average linkage) are
 *    grouped into one measure: the average of their standardised values, so they count once.
 * 5. Weight of a measure = its link with winning × its repeatability, scaled to sum to 1.
 *
 * Validation (validate()): standards derived from the first season alone rate that season's
 * players; their ratings are tested on the next season (team results, repeatability) against
 * simpler alternatives.
 *
 * @phpstan-type StatisticResult array{label: string, kind: string, direction: int, players: int, used: bool, reason: ?string, prior: ?float, slope: ?float, possession_mean: ?float, relevance: array<string, float>, reliability: ?float, proxy_scale: ?float, effective_players: array<string, float>}
 * @phpstan-type Measure array{key: string, label: string, members: list<string>, weight: float, relevance: float, reliability: float}
 * @phpstan-type RoleStandard array{players: int, seasons: array<string, int>, pairs: int, measures: list<Measure>, statistics: array<string, StatisticResult>}
 */
final class StandardsDeriver
{
    public const MIN_MINUTES = 900.0;
    /** A statistic must be known for this share of a role's players. */
    public const MIN_COVERAGE = 0.8;
    /** One-sided 5% significance (normal approximation, 100+ players per role and season). */
    public const Z_ONE_SIDED = 1.645;
    /** Statistics correlated at least this strongly ("very strong") form one measure. */
    public const SAME_THING = 0.8;
    /** Players in the role in both seasons needed to measure repeatability (and the prior). */
    public const MIN_PAIRS = 20;
    /** Validation: a team is rated when its rated players played this share of its minutes. */
    public const VALIDATION_COVERAGE = 0.2;

    /**
     * @param array<array-key, list<StandardsPlayer>> $seasons season code => players, in time order
     *
     * @return array<string, RoleStandard> by role
     */
    public function derive(array $seasons): array
    {
        $standards = [];
        foreach ($this->roles($seasons) as $role) {
            $standards[$role] = $this->deriveRole($role, $seasons, array_map('strval', array_keys($seasons)));
        }

        return $standards;
    }

    /**
     * Standards from the first season alone (its link with winning; repeatability uses the
     * second season's statistics, never its results) rate the first season's players. Tested:
     * - how well a team's next-season goal difference follows the first-season ratings of the
     *   players it fields next season (minutes-weighted), also beyond its previous goal difference;
     * - how well the same rule rates the same players alike in both seasons.
     * Against: the same measures weighted equally; every candidate statistic weighted equally
     * ("every good statistic counts"); weights by link with winning only.
     *
     * @param array<array-key, list<StandardsPlayer>> $seasons exactly two, in time order
     *
     * @return array<string, array{team_r: ?float, team_r_beyond_previous: ?float, teams: int, repeatability: ?float, players: int}>
     */
    public function validate(array $seasons): array
    {
        [$first, $second] = array_map('strval', array_keys($seasons));
        $schemes = ['standards' => [], 'equal_measures' => [], 'all_candidates' => [], 'winning_only' => []];
        foreach ($this->roles($seasons) as $role) {
            $standard = $this->deriveRole($role, $seasons, [$first]);
            foreach (array_keys($schemes) as $scheme) {
                $schemes[$scheme][$role] = $this->scheme($scheme, $standard);
            }
        }

        $previous = [];
        foreach ($seasons[$first] as $p) {
            if ($p->goalDifference !== null && $p->candidate->team !== null) {
                $previous[(string) $p->candidate->league . '|' . $p->candidate->team] = $p->goalDifference;
            }
        }

        $results = [];
        foreach ($schemes as $scheme => $standardsByRole) {
            $ratingsFirst = $this->ratings($seasons[$first], $standardsByRole);
            $ratingsSecond = $this->ratings($seasons[$second], $standardsByRole);

            // Teams of the second season, rated by their players' first-season ratings.
            $teams = [];
            foreach ($seasons[$second] as $p) {
                $key = (string) $p->candidate->league . '|' . $p->candidate->team;
                if ($p->goalDifference === null || $p->candidate->team === null) {
                    continue;
                }
                $teams[$key] ??= ['gd' => $p->goalDifference, 'minutes' => 0.0, 'rated' => 0.0, 'sum' => 0.0];
                $minutes = $p->candidate->stats->getMinutes();
                $teams[$key]['minutes'] += $minutes;
                if (isset($ratingsFirst[$p->key])) {
                    $teams[$key]['rated'] += $minutes;
                    $teams[$key]['sum'] += $ratingsFirst[$p->key] * $minutes;
                }
            }
            $x = [];
            $y = [];
            $prev = [];
            foreach ($teams as $key => $t) {
                if ($t['minutes'] > 0 && $t['rated'] / $t['minutes'] >= self::VALIDATION_COVERAGE && isset($previous[$key])) {
                    $x[] = $t['sum'] / $t['rated'];
                    $y[] = $t['gd'];
                    $prev[] = $previous[$key];
                }
            }

            $pairs = array_values(array_filter(array_map(
                static fn (string $key) => isset($ratingsSecond[$key]) ? [$ratingsFirst[$key], $ratingsSecond[$key]] : null,
                array_keys($ratingsFirst),
            )));

            $results[$scheme] = [
                'team_r' => Stats::correlation($x, $y),
                'team_r_beyond_previous' => count($x) > 2 ? Stats::correlation(Stats::residuals($x, $prev), Stats::residuals($y, $prev)) : null,
                'teams' => count($x),
                'repeatability' => Stats::correlation(array_column($pairs, 0), array_column($pairs, 1)),
                'players' => count($pairs),
            ];
        }

        return $results;
    }

    /**
     * @param array<array-key, list<StandardsPlayer>> $seasons
     * @param list<string>                            $relevanceSeasons the seasons whose link with winning decides
     *
     * @return RoleStandard
     */
    private function deriveRole(string $role, array $seasons, array $relevanceSeasons): array
    {
        $players = [];
        foreach ($seasons as $code => $list) {
            foreach ($list as $p) {
                if ($p->role() === $role && $p->candidate->stats->getMinutes() >= self::MIN_MINUTES && $p->goalDifference !== null && $p->possession !== null) {
                    $players[] = $p;
                }
            }
        }
        $codes = array_map('strval', array_keys($seasons)); // PHP turns '2526' into an integer key
        // Goal difference adjusted for possession, within the role and season.
        $gdResidual = [];
        foreach ($codes as $code) {
            $inSeason = array_values(array_filter($players, static fn (StandardsPlayer $p) => $p->season === $code));
            $residuals = Stats::residuals(array_map(static fn (StandardsPlayer $p) => (float) $p->goalDifference, $inSeason), array_map(static fn (StandardsPlayer $p) => (float) $p->possession, $inSeason));
            foreach ($inSeason as $i => $p) {
                $gdResidual[spl_object_id($p)] = $residuals[$i];
            }
        }
        $pairKeys = $this->pairKeys($players, $codes);

        $statistics = [];
        $values = []; // statistic => player object id => adjusted, oriented value
        foreach (StatisticCatalog::all() as $key => $statistic) {
            [$result, $playerValues] = $this->statistic($statistic, $players, $gdResidual, $pairKeys, $codes, $relevanceSeasons);
            $statistics[$key] = $result;
            if ($result['used']) {
                $values[$key] = $playerValues;
            }
        }

        // The measures' link with winning comes from the deciding seasons only (validation: the first).
        $deciding = array_values(array_filter($players, static fn (StandardsPlayer $p) => in_array($p->season, $relevanceSeasons, true)));
        $measures = $this->measures($values, $statistics, $players, $deciding, $gdResidual, $pairKeys);

        return [
            'players' => count($players),
            'seasons' => array_map(static fn (string $code) => count(array_filter($players, static fn (StandardsPlayer $p) => $p->season === $code)), array_combine($codes, $codes)),
            'pairs' => count($pairKeys),
            'measures' => $measures,
            'statistics' => $statistics,
        ];
    }

    /**
     * One statistic for one role: its measured values and the three tests.
     *
     * @param list<StandardsPlayer>                $players
     * @param array<int, float>                    $gdResidual
     * @param array<string, array{0: int, 1: int}> $pairKeys         player key => object ids in the two seasons
     * @param list<string>                         $codes
     * @param list<string>                         $relevanceSeasons
     *
     * @return array{0: StatisticResult, 1: array<int, float>}
     */
    private function statistic(Statistic $statistic, array $players, array $gdResidual, array $pairKeys, array $codes, array $relevanceSeasons): array
    {
        $result = [
            'label' => $statistic->label, 'kind' => $statistic->kind, 'direction' => $statistic->direction, 'players' => 0,
            'used' => false, 'reason' => null, 'prior' => null, 'slope' => null, 'possession_mean' => null, 'relevance' => [], 'reliability' => null,
            'proxy_scale' => null, 'effective_players' => [],
        ];

        $proxyScale = self::proxyScale($statistic, $players);
        $result['proxy_scale'] = $proxyScale;
        $measured = [];
        foreach ($players as $p) {
            $m = $statistic->measure($p->candidate->stats, $proxyScale);
            if ($m !== null) {
                $measured[spl_object_id($p)] = ['player' => $p, 'value' => $m[0], 'sample' => $m[1]];
            }
        }
        $result['players'] = count($measured);
        foreach ($codes as $code) {
            $inSeason = count(array_filter($players, static fn (StandardsPlayer $p) => $p->season === $code));
            $known = count(array_filter($measured, static fn (array $m) => $m['player']->season === $code));
            if ($inSeason > 0 && $known === 0) {
                return [['reason' => 'Not in the 20' . substr($code, 0, 2) . '/' . substr($code, 2, 2) . ' data'] + $result, []];
            }
        }
        if ($players === [] || count($measured) < self::MIN_COVERAGE * count($players)) {
            return [['reason' => 'Known for too few players in this role'] + $result, []];
        }

        $prior = self::prior($measured, $pairKeys);
        if ($prior === null) {
            return [['reason' => 'Does not repeat from one season to the next'] + $result, []];
        }
        $mean = Stats::mean(array_column($measured, 'value'));
        $transformed = array_map(
            static fn (array $m) => self::transform($statistic, ($m['value'] * $m['sample'] + $mean * $prior) / ($m['sample'] + $prior)),
            $measured,
        );
        $possession = array_map(static fn (array $m) => (float) $m['player']->possession, $measured);
        [$slope, $possessionMean] = Stats::slope(array_values($possession), array_values($transformed));
        $values = [];
        foreach ($transformed as $id => $t) {
            $values[$id] = $statistic->direction * ($t - $slope * ($possession[$id] - $possessionMean));
        }
        $result = ['prior' => $prior, 'slope' => $slope, 'possession_mean' => $possessionMean] + $result;

        // Link with winning, per season.
        $significant = true;
        foreach ($codes as $code) {
            $ids = array_keys(array_filter($measured, static fn (array $m) => $m['player']->season === $code));
            if (count($ids) < 3) {
                $significant = $significant && !in_array($code, $relevanceSeasons, true);
                continue;
            }
            $r = Stats::correlation(array_map(static fn (int $id) => $values[$id], $ids), array_map(static fn (int $id) => $gdResidual[$id], $ids)) ?? 0.0;
            $result['relevance'][$code] = $r;
            // Teammates share their team's results: the test counts teams' worth of evidence, not players'.
            $effective = self::effectiveSample(array_map(static fn (int $id) => $values[$id], $ids), array_map(static fn (int $id) => (string) $measured[$id]['player']->candidate->league . '|' . $measured[$id]['player']->candidate->team, $ids));
            $result['effective_players'][$code] = $effective;
            if (in_array($code, $relevanceSeasons, true) && $r < self::Z_ONE_SIDED / sqrt($effective)) {
                $significant = false;
            }
        }
        $result['reliability'] = self::reliability($values, $pairKeys);

        if (!$significant) {
            $result['reason'] = 'No significant link with winning in ' . (count($relevanceSeasons) > 1 ? 'each season' : 'the season');
        } elseif (($result['reliability'] ?? 0.0) <= 0.0) {
            $result['reason'] = 'Does not repeat from one season to the next';
        } else {
            $result['used'] = true;
        }

        return [$result, $values];
    }

    /**
     * Groups the used statistics into measures and weighs them.
     *
     * @param array<string, array<int, float>>     $values
     * @param array<string, StatisticResult>       $statistics
     * @param list<StandardsPlayer>                $players
     * @param list<StandardsPlayer>                $deciding   the players whose team results decide the link with winning
     * @param array<int, float>                    $gdResidual
     * @param array<string, array{0: int, 1: int}> $pairKeys
     *
     * @return list<Measure>
     */
    private function measures(array $values, array $statistics, array $players, array $deciding, array $gdResidual, array $pairKeys): array
    {
        $z = array_map(static fn (array $v) => Stats::standardise($v), $values);
        $clusters = Stats::cluster($z, self::SAME_THING);

        $measures = [];
        foreach ($clusters as $members) {
            $score = [];
            foreach ($players as $p) {
                $known = array_values(array_filter(array_map(static fn (string $m) => $z[$m][spl_object_id($p)] ?? null, $members), static fn (?float $v) => $v !== null));
                if ($known !== []) {
                    $score[spl_object_id($p)] = Stats::mean($known);
                }
            }
            $decidingScores = array_intersect_key($score, array_flip(array_map(static fn (StandardsPlayer $p) => spl_object_id($p), $deciding)));
            $relevance = Stats::correlation(array_values($decidingScores), array_map(static fn (int $id) => $gdResidual[$id], array_keys($decidingScores))) ?? 0.0;
            $reliability = self::reliability($score, $pairKeys) ?? 0.0;
            $measures[] = [
                'key' => $members[0],
                'label' => implode(' · ', array_map(static fn (string $m) => $statistics[$m]['label'], $members)),
                'members' => $members,
                'weight' => max(0.0, $relevance) * max(0.0, $reliability),
                'relevance' => $relevance,
                'reliability' => $reliability,
            ];
        }
        $measures = array_values(array_filter($measures, static fn (array $m) => $m['weight'] > 0));
        $total = array_sum(array_column($measures, 'weight'));
        foreach ($measures as $i => $m) {
            $measures[$i]['weight'] = $m['weight'] / $total;
        }
        usort($measures, static fn (array $a, array $b) => $b['weight'] <=> $a['weight']);

        return $measures;
    }

    /**
     * The validation alternatives, as standards over the same derivation.
     *
     * @param RoleStandard $standard
     *
     * @return RoleStandard
     */
    private function scheme(string $scheme, array $standard): array
    {
        if ($scheme === 'standards' || $standard['measures'] === []) {
            return $standard;
        }
        if ($scheme === 'all_candidates') {
            $all = array_keys(array_filter($standard['statistics'], static fn (array $s) => $s['prior'] !== null));
            $standard['measures'] = array_map(static fn (string $key) => ['key' => $key, 'label' => $key, 'members' => [$key], 'weight' => 1 / count($all), 'relevance' => 0.0, 'reliability' => 0.0], $all);

            return $standard;
        }
        $total = 0.0;
        foreach ($standard['measures'] as $i => $m) {
            $standard['measures'][$i]['weight'] = $scheme === 'equal_measures' ? 1.0 : max(0.0, $m['relevance']);
            $total += $standard['measures'][$i]['weight'];
        }
        foreach ($standard['measures'] as $i => $m) {
            $standard['measures'][$i]['weight'] = $total > 0 ? $m['weight'] / $total : 0.0;
        }

        return $standard;
    }

    /**
     * Ratings of a season's players (900+ minutes) under standards: the weighted mean of their
     * standardised measures, standardised within the role.
     *
     * @param list<StandardsPlayer>       $players
     * @param array<string, RoleStandard> $standards
     *
     * @return array<string, float> player key => rating
     */
    private function ratings(array $players, array $standards): array
    {
        $ratings = [];
        foreach ($standards as $role => $standard) {
            $inRole = array_values(array_filter($players, static fn (StandardsPlayer $p) => $p->role() === $role && $p->candidate->stats->getMinutes() >= self::MIN_MINUTES && $p->possession !== null));
            $z = [];
            foreach (array_unique(array_merge(...array_map(static fn (array $m) => $m['members'], $standard['measures'] ?: [['members' => []]]))) as $key) {
                $s = $standard['statistics'][$key];
                $statistic = StatisticCatalog::get($key);
                $values = [];
                foreach ($inRole as $p) {
                    $m = $statistic->measure($p->candidate->stats, $s['proxy_scale'] ?? null);
                    if ($m !== null && $s['prior'] !== null) {
                        $values[spl_object_id($p)] = $m;
                    }
                }
                if ($values === []) {
                    continue;
                }
                $mean = Stats::mean(array_column($values, 0));
                $adjusted = [];
                foreach ($inRole as $p) {
                    $m = $values[spl_object_id($p)] ?? null;
                    if ($m !== null) {
                        $t = self::transform($statistic, ($m[0] * $m[1] + $mean * $s['prior']) / ($m[1] + $s['prior']));
                        $adjusted[spl_object_id($p)] = $statistic->direction * ($t - (float) $s['slope'] * ((float) $p->possession - (float) $s['possession_mean']));
                    }
                }
                $z[$key] = Stats::standardise($adjusted);
            }

            $raw = [];
            foreach ($inRole as $p) {
                $sum = 0.0;
                $weight = 0.0;
                foreach ($standard['measures'] as $measure) {
                    $known = array_values(array_filter(array_map(static fn (string $k) => $z[$k][spl_object_id($p)] ?? null, $measure['members']), static fn (?float $v) => $v !== null));
                    if ($known !== []) {
                        $sum += $measure['weight'] * Stats::mean($known);
                        $weight += $measure['weight'];
                    }
                }
                if ($weight > 0) {
                    $raw[$p->key] = $sum / $weight;
                }
            }
            $ratings += Stats::standardise($raw);
        }

        return $ratings;
    }

    /**
     * Empirical Bayes prior from repeatability: a value over n matches (or attempts) is kept for
     * n / (n + k) of its distance from the average. If the statistic repeats from one season to
     * the next with correlation ρ over a typical sample n̄, that share is ρ, so k = n̄ (1 − ρ) / ρ.
     * Measured on the raw values; no distribution is assumed. Null when it does not repeat.
     *
     * @param array<int, array{player: StandardsPlayer, value: float, sample: float}> $measured by player object id
     * @param array<string, array{0: int, 1: int}>                                    $pairKeys
     */
    private static function prior(array $measured, array $pairKeys): ?float
    {
        $a = [];
        $b = [];
        $samples = [];
        foreach ($pairKeys as [$first, $second]) {
            if (isset($measured[$first], $measured[$second])) {
                $a[] = $measured[$first]['value'];
                $b[] = $measured[$second]['value'];
                $samples[] = $measured[$first]['sample'];
                $samples[] = $measured[$second]['sample'];
            }
        }
        $rho = count($a) >= self::MIN_PAIRS ? Stats::correlation($a, $b) : null;
        // The harmonic mean is the typical sample of unequal samples for averaging noise (1 / n).
        $typical = count($samples) / array_sum(array_map(static fn (float $n) => 1 / max($n, 1e-9), $samples));

        return $rho !== null && $rho > 0 ? $typical * (1 - $rho) / $rho : null;
    }

    /**
     * Scale of a statistic's proxy (another provider's estimate) to the statistic itself: ratio
     * of their sums over the role's players who have both. Null without a proxy.
     *
     * @param list<StandardsPlayer> $players
     */
    private static function proxyScale(Statistic $statistic, array $players): ?float
    {
        if ($statistic->proxy === []) {
            return null;
        }
        $primary = 0.0;
        $proxy = 0.0;
        foreach ($players as $p) {
            $a = $statistic->per90($p->candidate->stats);
            $b = $statistic->proxyPer90($p->candidate->stats);
            if ($a !== null && $b !== null) {
                $primary += $a[0];
                $proxy += $b[0];
            }
        }

        return $proxy > 0 ? $primary / $proxy : null;
    }

    /**
     * Players' worth of independent evidence about a link with team results: n over the design
     * effect 1 + (m̄ − 1) × ICC, with m̄ players per team and ICC how much the statistic is shared
     * by teammates (a team-level outcome counts once per team, not once per player).
     *
     * @param list<float>  $values
     * @param list<string> $teams
     */
    private static function effectiveSample(array $values, array $teams): float
    {
        $n = count($values);
        $perTeam = $n / max(1, count(array_unique($teams)));

        return $n / (1 + ($perTeam - 1) * Stats::intraclassCorrelation($values, $teams));
    }

    private static function transform(Statistic $statistic, float $value): float
    {
        return $statistic->kind === Statistic::PER90 ? sqrt(max(0.0, $value)) : $value;
    }

    /**
     * @param array<int, float>                    $values   by player object id
     * @param array<string, array{0: int, 1: int}> $pairKeys
     */
    private static function reliability(array $values, array $pairKeys): ?float
    {
        $a = [];
        $b = [];
        foreach ($pairKeys as [$first, $second]) {
            if (isset($values[$first], $values[$second])) {
                $a[] = $values[$first];
                $b[] = $values[$second];
            }
        }

        return count($a) >= self::MIN_PAIRS ? Stats::correlation($a, $b) : null;
    }

    /**
     * Players in the role in both seasons.
     *
     * @param list<StandardsPlayer> $players
     * @param list<string>          $codes
     *
     * @return array<string, array{0: int, 1: int}>
     */
    private function pairKeys(array $players, array $codes): array
    {
        if (count($codes) < 2) {
            return [];
        }
        $bySeason = [];
        foreach ($players as $p) {
            $bySeason[$p->season][$p->key] = spl_object_id($p);
        }
        $pairs = [];
        foreach ($bySeason[$codes[0]] ?? [] as $key => $id) {
            if (isset($bySeason[$codes[1]][$key])) {
                $pairs[$key] = [$id, $bySeason[$codes[1]][$key]];
            }
        }

        return $pairs;
    }

    /**
     * @param array<array-key, list<StandardsPlayer>> $seasons
     *
     * @return list<string>
     */
    private function roles(array $seasons): array
    {
        $roles = [];
        foreach ($seasons as $players) {
            foreach ($players as $p) {
                if ($p->role() !== null) {
                    $roles[$p->role()] = true;
                }
            }
        }

        return array_values(array_intersect(array_keys(\App\Football\Data\RoleCatalog::ROLES), array_keys($roles)));
    }
}
