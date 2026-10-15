<?php

declare(strict_types=1);

namespace App\Tests\Unit\Football;

use App\Entity\ImportBatch;
use App\Entity\Player;
use App\Entity\PlayerCompetitionStats;
use App\Entity\PlayerSeason;
use App\Entity\Season;
use App\Entity\StatsScope;
use App\Football\Data\FieldCatalog;
use App\Football\Scoring\ScoringCandidate;
use App\Football\Standards\StandardsDeriver;
use App\Football\Standards\StandardsPlayer;
use App\Football\Standards\Stats;
use PHPUnit\Framework\TestCase;

final class StandardsDeriverTest extends TestCase
{
    private const PLAYERS = 160;
    private const MINUTES = 1800.0;

    /** @var array<array-key, list<StandardsPlayer>> */
    private static array $seasons = [];

    /** @var array<string, mixed> */
    private static array $standards = [];

    public static function setUpBeforeClass(): void
    {
        // A stable quality per player drives his team's results and his key passes (plus noise);
        // big chances created nearly duplicate key passes; fouls are noise; tackles won follow
        // his team's possession only (which is unrelated to results here).
        mt_srand(7);
        $noise = static fn (float $sd) => $sd * (mt_rand() / mt_getrandmax() - 0.5) * 3.46;
        $quality = array_map(static fn () => $noise(1.0), range(1, self::PLAYERS));
        $teamPossession = array_map(static fn () => 0.5 + $noise(0.05), range(1, self::PLAYERS));
        foreach (['2425', '2526'] as $season) {
            $players = [];
            foreach ($quality as $i => $q) {
                $possession = $teamPossession[$i];
                $keyPasses = 2.0 + 0.6 * $q + $noise(0.25);
                $players[] = self::player($season, $i, [
                    'key_passes' => $keyPasses,
                    'big_chances_created' => 0.5 * $keyPasses + $noise(0.05),
                    'fouls' => 1.5 + $noise(0.4),
                    'tackles_won' => 1.0 + 8 * ($possession - 0.5) + $noise(0.1),
                ], 0.4 * $q + $noise(0.4), $possession);
            }
            self::$seasons[$season] = $players;
        }
        self::$standards = (new StandardsDeriver())->derive(self::$seasons);
    }

    public function testAStatisticThatGoesWithWinningAndRepeatsIsUsedAndDuplicatesCountOnce(): void
    {
        $st = self::$standards['ST'];

        self::assertCount(1, $st['measures']);
        self::assertSame(['big_chances_created', 'key_passes'], self::sorted($st['measures'][0]['members']), 'nearly the same statistic: one measure');
        self::assertEqualsWithDelta(1.0, $st['measures'][0]['weight'], 1e-12);
        self::assertTrue($st['statistics']['key_passes']['used']);
        self::assertGreaterThan(0.5, $st['statistics']['key_passes']['reliability']);
    }

    public function testNoiseAndPossessionAloneAreNotUsed(): void
    {
        $statistics = self::$standards['ST']['statistics'];

        self::assertFalse($statistics['fouls']['used']);
        self::assertFalse($statistics['tackles_won']['used'], 'what possession explains is removed first');
        // Tackles won = 1 + 8 (p − 0.5): on the square-root scale the slope near p = 0.5 is 8 / (2 × √1) = 4.
        self::assertEqualsWithDelta(4.0, $statistics['tackles_won']['slope'], 0.3);
        self::assertSame('Known for too few players in this role', $statistics['np_goals']['reason']);
    }

    public function testThePriorFollowsFromRepeatability(): void
    {
        // k = n̄ (1 − ρ) / ρ, with ρ the season-to-season correlation of the raw values.
        $raw = static fn (string $season) => array_map(static fn (StandardsPlayer $p) => (float) $p->candidate->stats->per90('key_passes'), self::$seasons[$season]);
        $rho = (float) Stats::correlation($raw('2425'), $raw('2526'));

        self::assertEqualsWithDelta(self::MINUTES / 90 * (1 - $rho) / $rho, self::$standards['ST']['statistics']['key_passes']['prior'], 1e-9);
    }

    public function testValidationComparesTheMethodsOnTheNextSeason(): void
    {
        $validation = (new StandardsDeriver())->validate(self::$seasons);

        self::assertSame(['standards', 'equal_measures', 'all_candidates', 'winning_only'], array_keys($validation));
        self::assertSame(self::PLAYERS, $validation['standards']['players']);
        self::assertGreaterThan(0.5, $validation['standards']['repeatability']);
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    /** @param array<string, float> $per90 */
    private static function player(string $season, int $i, array $per90, float $goalDifference, float $possession): StandardsPlayer
    {
        $values = array_map(static fn (float $v) => max(0.0, $v) * self::MINUTES / 90, $per90);
        $playerSeason = new PlayerSeason(new Player("P{$i}"), new Season($season, new \DateTimeImmutable(), new \DateTimeImmutable()));
        $stats = new PlayerCompetitionStats(
            $playerSeason,
            StatsScope::League,
            new ImportBatch('test', 'test', $season, 'test', '1'),
            self::MINUTES,
            $values,
            array_map(static fn (string $f) => FieldCatalog::get($f)->origin->value, array_combine(array_keys($values), array_keys($values))),
            ['FBref' => ['status' => 'ok', 'minutes' => self::MINUTES, 'reason' => null], 'SofaScore' => ['status' => 'ok', 'minutes' => self::MINUTES, 'reason' => null]],
        );

        return new StandardsPlayer("P{$i}|2000", $season, new ScoringCandidate($i, "P{$i}", 'FW', "Team{$i}", $stats, 'PL', 'ST'), $goalDifference, $possession);
    }
}
