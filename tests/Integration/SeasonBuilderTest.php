<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\DataImport\EmptyImportException;
use App\DataImport\Identity\IdentityOverrides;
use App\Entity\Player;
use App\Entity\PlayerSeason;
use App\Entity\StatsScope;
use App\Football\Data\Origin;
use App\Football\Data\OriginStatus;
use App\Tests\Support\PipelineTrait;
use App\Tests\Support\SourceFixtures as F;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Runs the real pipeline against the PostgreSQL test database. */
final class SeasonBuilderTest extends KernelTestCase
{
    use PipelineTrait;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->resetDatabase();
    }

    public function testRebuildingCreatesNoDuplicatesAndKeepsIds(): void
    {
        $this->importDefaultSources();
        $first = $this->build();
        $ids = $this->playerIds();
        $second = $this->build();

        self::assertSame(3, $first['players_created']);
        self::assertSame(0, $second['players_created']);
        self::assertSame(0, $second['teams_created']);
        self::assertSame(0, $second['identifiers_added']);
        self::assertSame($ids, $this->playerIds());
        self::assertSame(3, $this->em()->getRepository(PlayerSeason::class)->count([]));
    }

    public function testLeagueAndClStatsBelongToOnePlayerSeason(): void
    {
        $this->importDefaultSources();
        $this->build();

        $season = $this->seasonOf('Kylian Mbappé');
        $league = $this->statsOf('Kylian Mbappé', StatsScope::League);
        $cl = $this->statsOf('Kylian Mbappé', StatsScope::ChampionsLeague);

        self::assertSame(25.0, $league->value('goals'));
        self::assertSame(Origin::Fbref, $league->origin('goals'));
        self::assertSame(15.0, $cl->value('goals'));
        self::assertSame(Origin::Sofascore, $cl->origin('goals'));
        self::assertSame('Real Madrid', $season->getMainTeam()?->getName());
        self::assertSame(['PD'], $season->getStints()->map(static fn ($s) => $s->getCompetition()->getCode())->getValues());
        self::assertSame('top5-football-dataset', $league->getBatch()->getSource());
    }

    public function testClOnlyPlayerGetsASeasonWithClStatsOnly(): void
    {
        $this->importDefaultSources();
        $this->build();

        $season = $this->seasonOf('Victor Osimhen');
        $team = $season->getMainTeam();

        self::assertNull($season->getStats(StatsScope::League));
        self::assertSame(7.0, $this->statsOf('Victor Osimhen', StatsScope::ChampionsLeague)->value('goals'));
        self::assertNotNull($team);
        self::assertSame('Galatasaray', $team->getName());
        self::assertNull($team->getDomesticLeague());
    }

    public function testNewDatasetVersionUpdatesStatsButNotIdentity(): void
    {
        $this->importDefaultSources();
        $this->build();
        $id = $this->seasonOf('Kylian Mbappé')->getPlayer()->getId();

        // The source corrects a number: a new batch, same player.
        $this->importTop5([F::top5Row(['player' => 'Kylian Mbappé', 'born' => '1998', 'team' => 'Real Madrid', 'league' => 'ESP-La Liga', 'fb_gls' => '26', 'ss_player_id' => '826643.0'])]);
        $this->build();

        $stats = $this->statsOf('Kylian Mbappé', StatsScope::League);
        self::assertSame($id, $stats->getPlayerSeason()->getPlayer()->getId());
        self::assertSame(26.0, $stats->value('goals'));
        self::assertSame(3, $stats->getBatch()->getId(), 'batches 1 and 2 are the default sources');
    }

    public function testTransferBetweenLeaguesKeepsProviderMinutesForPer90(): void
    {
        $this->importTop5([
            F::top5Row(['player' => 'Piero Hincapié', 'born' => '2002', 'team' => 'Arsenal', 'playing_time_min' => '1792', 'playing_time_mp' => '20', 'ss_minutesPlayed' => '90', 'ss_tackles' => '3']),
            F::top5Row(['player' => 'Piero Hincapié', 'born' => '2002', 'team' => 'Leverkusen', 'league' => 'GER-Bundesliga', 'playing_time_min' => '90', 'playing_time_mp' => '1', 'ss_minutesPlayed' => '90', 'ss_tackles' => '3']),
        ]);
        $this->importCl([F::sofascoreRecord(1, 'Someone Else', 1, 'Other Club')]);
        $this->build();

        $stats = $this->statsOf('Piero Hincapié', StatsScope::League);
        self::assertSame(1882.0, $stats->getMinutes());
        self::assertSame(OriginStatus::Partial, $stats->originStatus(Origin::Sofascore));
        self::assertEqualsWithDelta(3.0, $stats->per90('tackles'), 0.001, '3 tackles in 90 SofaScore minutes, not in 1882');
        self::assertFalse($stats->trusted('tackles'));
        self::assertCount(2, $this->seasonOf('Piero Hincapié')->getStints());
    }

    public function testRebuildAfterALinkIsLostKeepsTheIdentityInsteadOfCrashing(): void
    {
        // Build 1: CL "Alejandro Grimaldo" is linked by initial + surname.
        $this->importTop5([F::top5Row(['player' => 'Álex Grimaldo', 'born' => '1995', 'team' => 'Leverkusen', 'league' => 'GER-Bundesliga'])]);
        $this->importCl([F::sofascoreRecord(55, 'Alejandro Grimaldo', 2681, 'Bayer 04 Leverkusen')]);
        $this->build();

        // Build 2: a second "A. Grimaldo" makes the name ambiguous; the matcher no longer links.
        $this->importTop5([
            F::top5Row(['player' => 'Álex Grimaldo', 'born' => '1995', 'team' => 'Leverkusen', 'league' => 'GER-Bundesliga']),
            F::top5Row(['player' => 'Adrián Grimaldo', 'born' => '2001', 'team' => 'Sevilla', 'league' => 'ESP-La Liga']),
        ]);
        $result = $this->build();

        self::assertSame(1, $result['players_created'], 'only Adrián is new');
        self::assertSame(600.0, $this->statsOf('Álex Grimaldo', StatsScope::ChampionsLeague)->getMinutes());
    }

    public function testManualLinkIsStableAcrossRebuilds(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'identity');
        file_put_contents($file, "sofascore:\n  2: 'Player B|1991'\n");
        self::getContainer()->set(IdentityOverrides::class, new IdentityOverrides($file));

        // B's league row carries CL player 1's id by mistake; the manual link says CL player 2 is B.
        $this->importTop5([F::top5Row(['player' => 'Player B', 'born' => '1991', 'team' => 'Y', 'ss_player_id' => '2.0'])]);
        $this->importCl([F::sofascoreRecord(1, 'Player A', 9, 'Y FC'), F::sofascoreRecord(2, 'Player B', 9, 'Y FC', ['goals' => 4])]);
        $this->build();
        $second = $this->build();
        unlink($file);

        self::assertSame(0, $second['identifiers_added']);
        self::assertSame([], $second['relinked']);
        self::assertSame(4.0, $this->statsOf('Player B', StatsScope::ChampionsLeague)->value('goals'));
    }

    public function testAPlayerWithTwoClRecordsKeepsOneAndReportsTheOther(): void
    {
        $this->importTop5([F::top5Row(['player' => 'Kylian Mbappé', 'born' => '1998', 'team' => 'Real Madrid', 'league' => 'ESP-La Liga', 'ss_player_id' => '826643.0'])]);
        $this->importCl([F::sofascoreRecord(826643, 'Kylian Mbappé', 2829, 'Real Madrid'), F::sofascoreRecord(826643, 'Kylian Mbappé', 1, 'Other Club')]);

        $result = $this->build();

        self::assertCount(1, $result['rejected']);
        self::assertStringContainsString('already has CL statistics', $result['rejected'][0]);
    }

    public function testEmptyImportIsRefused(): void
    {
        $this->expectException(EmptyImportException::class);
        $this->importCl([]);
    }

    private function importDefaultSources(): void
    {
        $this->importTop5([
            F::top5Row(['player' => 'Kylian Mbappé', 'born' => '1998', 'team' => 'Real Madrid', 'league' => 'ESP-La Liga', 'fb_gls' => '25', 'ss_goals' => '25', 'ss_player_id' => '826643.0']),
            F::top5Row(['player' => 'Fabián Ruiz Peña', 'born' => '1996', 'team' => 'PSG', 'league' => 'FRA-Ligue 1']),
        ]);
        $this->importCl([
            F::sofascoreRecord(826643, 'Kylian Mbappé', 2829, 'Real Madrid', ['goals' => 15]),
            F::sofascoreRecord(1, 'Victor Osimhen', 3061, 'Galatasaray', ['goals' => 7]),
        ]);
    }

    /** @return array<string, int> name => id */
    private function playerIds(): array
    {
        $ids = [];
        foreach ($this->em()->getRepository(Player::class)->findAll() as $player) {
            $ids[$player->getName()] = (int) $player->getId();
        }
        ksort($ids);

        return $ids;
    }
}
