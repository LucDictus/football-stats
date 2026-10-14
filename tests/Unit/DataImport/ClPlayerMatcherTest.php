<?php

declare(strict_types=1);

namespace App\Tests\Unit\DataImport;

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

        self::assertSame(ClPlayerMatcher::METHOD_ID, $result->linkFor('826643|1')['method']);
    }

    public function testManualLinkWinsOverEverything(): void
    {
        $result = $this->match(
            [$this->league('A|1990', 'Someone Else', 'X', 1), $this->league('B|1991', 'Target', 'Y')],
            [$this->cl(1, 'Someone Else', 'X')],
            [1 => 'B|1991'],
        );

        self::assertSame('B|1991', $result->linkFor('1|1')['player']->key);
        self::assertSame(ClPlayerMatcher::METHOD_MANUAL, $result->linkFor('1|1')['method']);
    }

    public function testInitialAndSurnameForPlayersWithoutId(): void
    {
        $result = $this->match([$this->league('Álex Grimaldo|1995', 'Álex Grimaldo', 'Leverkusen')], [$this->cl(5, 'Alejandro Grimaldo', 'Bayer 04 Leverkusen')]);

        self::assertSame(ClPlayerMatcher::METHOD_NAME, $result->linkFor('5|1')['method']);
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
            ...$this->teammates('PSG', 'Paris Saint-Germain'),
        ];
        $cl = [$this->cl(1093695, 'Vitinha', 'Paris Saint-Germain'), ...$this->clTeammates('Paris Saint-Germain')];

        $result = $this->match($league, $cl);

        self::assertSame('Vitinha|2000|PSG', $result->linkFor('1093695|1')['player']->key);
        self::assertSame('PSG', $result->clubs['Paris Saint-Germain']);
    }

    public function testNameVariantAtTheSameClub(): void
    {
        $league = [$this->league('Lee Kang-in|2001', 'Lee Kang-in', 'PSG'), ...$this->teammates('PSG', 'Paris Saint-Germain')];
        $cl = [$this->cl(917087, 'Kang-in Lee', 'Paris Saint-Germain'), ...$this->clTeammates('Paris Saint-Germain')];

        self::assertSame(ClPlayerMatcher::METHOD_NAME_CLUB, $this->match($league, $cl)->linkFor('917087|1')['method']);
    }

    public function testNameVariantAtAnotherClubIsNotLinked(): void
    {
        $league = [$this->league('Fabián Ruiz Peña|1996', 'Fabián Ruiz Peña', 'Real Betis'), ...$this->teammates('PSG', 'Paris Saint-Germain')];
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

    /**
     * @param list<MergedPlayerSeason>            $league
     * @param list<array<string, mixed>>          $cl
     * @param array<int, string>                  $manual
     */
    private function match(array $league, array $cl, array $manual = []): \App\DataImport\Identity\ClMatchResult
    {
        $byId = [];
        foreach ($cl as $record) {
            $byId[$record['player']['id'] . '|1'] = $record;
        }

        return (new ClPlayerMatcher())->match($league, $byId, $manual);
    }

    private function league(string $key, string $name, string $team, ?int $sofascoreId = null): MergedPlayerSeason
    {
        $ok = new OriginCheck(OriginStatus::Ok, 2000.0);

        return new MergedPlayerSeason(
            $key, $name, 1995, 'MF', false, [$team], ['FRA-Ligue 1'], $sofascoreId, 1, 2000.0, [],
            [Origin::Fbref->value => $ok, Origin::Understat->value => $ok,
             Origin::Sofascore->value => $sofascoreId !== null ? $ok : new OriginCheck(OriginStatus::Missing, null)],
        );
    }

    /** @return array<string, mixed> */
    private function cl(int $id, string $name, string $team): array
    {
        return ['player' => ['id' => $id, 'name' => $name], 'team' => ['id' => crc32($team), 'name' => $team], 'minutesPlayed' => 500];
    }

    /** Three id-linked teammates, so the club mapping CL club => league club can be learned. */
    private function teammates(string $leagueTeam, string $clTeam): array
    {
        return array_map(fn ($i) => $this->league("Mate {$i}|1990", "Mate {$i}", $leagueTeam, 1000 + $i), [1, 2, 3]);
    }

    private function clTeammates(string $clTeam): array
    {
        return array_map(fn ($i) => $this->cl(1000 + $i, "Mate {$i}", $clTeam), [1, 2, 3]);
    }
}
