<?php

declare(strict_types=1);

namespace App\Tests\Unit\DataImport;

use App\DataImport\Identity\ClMatchResult;
use App\DataImport\Identity\ClPlayerMatcher;
use App\DataQuality\MergedPlayerSeason;
use App\Football\Data\Origin;
use App\Football\Data\OriginCheck;
use App\Football\Data\OriginStatus;
use PHPUnit\Framework\TestCase;

final class ClPlayerMatcherTest extends TestCase
{
    public function testLinksBySofascoreId(): void
    {
        $result = $this->match([$this->league('Kylian Mbappé|1998', 'Kylian Mbappé', 'Real Madrid', 826643)], [$this->cl(826643, 'Kylian Mbappé', 'Real Madrid')]);

        self::assertSame(ClPlayerMatcher::METHOD_ID, $this->link($result, '826643|1')['method']);
    }

    public function testManualLinkWinsOverEverything(): void
    {
        $result = $this->match(
            [$this->league('A|1990', 'Someone Else', 'X', 1), $this->league('B|1991', 'Target', 'Y')],
            [$this->cl(1, 'Someone Else', 'X')],
            [1 => 'B|1991'],
        );

        self::assertSame('B|1991', $this->link($result, '1|1')['player']->key);
        self::assertSame(ClPlayerMatcher::METHOD_MANUAL, $this->link($result, '1|1')['method']);
    }

    public function testInitialAndSurnameForPlayersWithoutId(): void
    {
        $result = $this->match([$this->league('Álex Grimaldo|1995', 'Álex Grimaldo', 'Leverkusen')], [$this->cl(5, 'Alejandro Grimaldo', 'Bayer 04 Leverkusen')]);

        self::assertSame(ClPlayerMatcher::METHOD_NAME, $this->link($result, '5|1')['method']);
    }

    public function testNameRulesIgnorePlayersThatHaveATrustedId(): void
    {
        // The league Grimaldo has his own SofaScore id: a CL record with another id is someone else.
        $result = $this->match([$this->league('Álex Grimaldo|1995', 'Álex Grimaldo', 'Leverkusen', 99)], [$this->cl(5, 'Alejandro Grimaldo', 'Bayer 04 Leverkusen')]);

        self::assertNull($result->linkFor('5|1'));
    }

    public function testAmbiguousNameIsResolvedByClub(): void
    {
        $league = [
            $this->league('Vitinha|2000|PSG', 'Vitinha', 'PSG'),
            $this->league('Vitinha|2000|Genoa', 'Vitinha', 'Genoa'),
            ...$this->teammates('PSG'),
        ];
        $cl = [$this->cl(1093695, 'Vitinha', 'Paris Saint-Germain'), ...$this->clTeammates('Paris Saint-Germain')];

        $result = $this->match($league, $cl);

        self::assertSame('Vitinha|2000|PSG', $this->link($result, '1093695|1')['player']->key);
        self::assertSame('PSG', $result->clubs['Paris Saint-Germain']);
    }

    public function testNameVariantAtTheSameClub(): void
    {
        $league = [$this->league('Lee Kang-in|2001', 'Lee Kang-in', 'PSG'), ...$this->teammates('PSG')];
        $cl = [$this->cl(917087, 'Kang-in Lee', 'Paris Saint-Germain'), ...$this->clTeammates('Paris Saint-Germain')];

        self::assertSame(ClPlayerMatcher::METHOD_NAME_CLUB, $this->link($this->match($league, $cl), '917087|1')['method']);
    }

    public function testNameVariantAtAnotherClubIsNotLinked(): void
    {
        $league = [$this->league('Fabián Ruiz Peña|1996', 'Fabián Ruiz Peña', 'Real Betis'), ...$this->teammates('PSG')];
        $cl = [$this->cl(784655, 'Fabián Ruiz', 'Paris Saint-Germain'), ...$this->clTeammates('Paris Saint-Germain')];

        self::assertNull($this->match($league, $cl)->linkFor('784655|1'));
    }

    public function testALeaguePlayerIsLinkedOnlyOnce(): void
    {
        $result = $this->match(
            [$this->league('Sávio|2004', 'Sávio', 'Manchester City')],
            [$this->cl(1, 'Sávio', 'Manchester City'), $this->cl(2, 'Sávio', 'Manchester City')],
        );

        self::assertCount(1, $result->links);
    }

    public function testManualLinkAndIdCannotClaimTheSamePlayer(): void
    {
        // The league row of B carries CL player 1's id by mistake; a manual link sends CL player 2 to B.
        $result = $this->match(
            [$this->league('B|1991', 'Player B', 'Y', 1)],
            [$this->cl(1, 'Player A', 'Y'), $this->cl(2, 'Player B', 'Y')],
            [2 => 'B|1991'],
        );

        self::assertCount(1, $result->links);
        self::assertSame(ClPlayerMatcher::METHOD_MANUAL, $this->link($result, '2|1')['method']);
    }

    public function testOnePlayerListedForTwoClClubsIsLinkedOnce(): void
    {
        $league = [$this->league('A|1990', 'Player A', 'X', 7)];
        $cl = ['7|1' => $this->cl(7, 'Player A', 'X'), '7|2' => $this->cl(7, 'Player A', 'Z')];

        $result = (new ClPlayerMatcher())->match($league, $cl);

        self::assertCount(1, $result->links);
    }

    public function testSuspectIdIsNotTrustedButNameRulesStillApply(): void
    {
        $player = $this->league('Wataru Endo|1993', 'Wataru Endo', 'Liverpool', 5, OriginStatus::Suspect);

        $result = $this->match([$player], [$this->cl(5, 'Wataru Endo', 'Liverpool FC')]);

        self::assertSame(ClPlayerMatcher::METHOD_NAME, $this->link($result, '5|1')['method']);
    }

    /** @return array{player: MergedPlayerSeason, method: string} */
    private function link(ClMatchResult $result, string $externalId): array
    {
        $link = $result->linkFor($externalId);
        self::assertNotNull($link, "{$externalId} is not linked");

        return $link;
    }

    /**
     * @param array<MergedPlayerSeason>   $league
     * @param array<array<string, mixed>> $cl
     * @param array<int, string>          $manual
     */
    private function match(array $league, array $cl, array $manual = []): ClMatchResult
    {
        $byId = [];
        foreach ($cl as $record) {
            $byId[$record['player']['id'] . '|1'] = $record;
        }

        return (new ClPlayerMatcher())->match(array_values($league), $byId, $manual);
    }

    private function league(string $key, string $name, string $team, ?int $sofascoreId = null, OriginStatus $sofascoreStatus = OriginStatus::Ok): MergedPlayerSeason
    {
        $ok = new OriginCheck(OriginStatus::Ok, 2000.0);

        return new MergedPlayerSeason(
            $key, $name, 1995, 'MF', false, [$team], ['FRA-Ligue 1'], $sofascoreId, 1, 2000.0, [],
            [Origin::Fbref->value => $ok, Origin::Understat->value => $ok,
                Origin::Sofascore->value => $sofascoreId !== null ? new OriginCheck($sofascoreStatus, 2000.0) : new OriginCheck(OriginStatus::Missing, null)],
        );
    }

    /** @return array<string, mixed> */
    private function cl(int $id, string $name, string $team): array
    {
        return ['player' => ['id' => $id, 'name' => $name], 'team' => ['id' => crc32($team), 'name' => $team], 'minutesPlayed' => 500];
    }

    /**
     * Three id-linked teammates, so the club mapping CL club => league club can be learned.
     *
     * @return list<MergedPlayerSeason>
     */
    private function teammates(string $leagueTeam): array
    {
        return array_map(fn ($i) => $this->league("Mate {$i}|1990", "Mate {$i}", $leagueTeam, 1000 + $i), [1, 2, 3]);
    }

    /** @return list<array<string, mixed>> */
    private function clTeammates(string $clTeam): array
    {
        return array_map(fn ($i) => $this->cl(1000 + $i, "Mate {$i}", $clTeam), [1, 2, 3]);
    }
}
