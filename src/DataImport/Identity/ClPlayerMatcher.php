<?php

declare(strict_types=1);

namespace App\DataImport\Identity;

use App\DataQuality\MergedPlayerSeason;
use App\Football\Data\Origin;
use App\Football\Data\OriginStatus;

use function Symfony\Component\String\u;

/**
 * Links Champions League records (SofaScore) to league players (Top-5 dataset).
 *
 * In order, first match wins, and a league player is linked to at most one CL record:
 *   1. manual mapping (config/identity.yaml)
 *   2. SofaScore id: the league row carries the same, trusted, unshared id
 *   3. name: first initial + surname, unique (narrowed by club when ambiguous)
 *   4. name + club: same club, and one name's words contain the other's
 *      ("Fabián Ruiz" / "Fabián Ruiz Peña", "Kang-in Lee" / "Lee Kang-in")
 * Rules 3 and 4 only consider league players without a usable SofaScore id.
 */
final class ClPlayerMatcher
{
    public const METHOD_MANUAL = 'manual';
    public const METHOD_ID = 'sofascore id';
    public const METHOD_NAME = 'initial + surname';
    public const METHOD_NAME_CLUB = 'name + club';

    /**
     * @param list<MergedPlayerSeason>            $league
     * @param array<string, array<string, mixed>> $cl     external id => SofaScore record
     * @param array<int, string>                  $manual SofaScore player id => league player key
     */
    public function match(array $league, array $cl, array $manual = []): ClMatchResult
    {
        $byKey = [];
        $byId = [];
        $unidentified = [];
        foreach ($league as $player) {
            $byKey[$player->key] = $player;
            if ($player->sofascoreId !== null) {
                $byId[$player->sofascoreId] = $player;
            }
            if ($player->sofascoreId === null || $player->check(Origin::Sofascore)->status === OriginStatus::Suspect) {
                $unidentified[] = $player;
            }
        }

        $links = [];
        $claimed = [];
        $link = static function (string $externalId, MergedPlayerSeason $player, string $method) use (&$links, &$claimed): void {
            $links[$externalId] = ['player' => $player, 'method' => $method];
            $claimed[$player->key] = true;
        };

        // 1 and 2: exact links first, so name rules cannot take a player that has an exact match.
        foreach ($cl as $externalId => $record) {
            $id = (int) $record['player']['id'];
            if (isset($manual[$id], $byKey[$manual[$id]])) {
                $link($externalId, $byKey[$manual[$id]], self::METHOD_MANUAL);
            } elseif (isset($byId[$id])) {
                $link($externalId, $byId[$id], self::METHOD_ID);
            }
        }

        $clubs = $this->clubMapping($cl, $links);

        // 3 and 4: name rules for the rest.
        foreach ($cl as $externalId => $record) {
            if (isset($links[$externalId])) {
                continue;
            }
            $available = array_values(array_filter($unidentified, static fn ($p) => !isset($claimed[$p->key])));
            $club = $clubs[$record['team']['name']] ?? null;
            $name = $record['player']['name'];

            $candidates = array_values(array_filter($available, static fn ($p) => self::shortName($p->name) === self::shortName($name)));
            if (count($candidates) > 1 && $club !== null) {
                $candidates = array_values(array_filter($candidates, static fn ($p) => in_array($club, $p->teams, true)));
            }
            if (count($candidates) === 1) {
                $link($externalId, $candidates[0], self::METHOD_NAME);
                continue;
            }

            if ($club !== null) {
                $candidates = array_values(array_filter(
                    $available,
                    static fn ($p) => in_array($club, $p->teams, true) && self::nameContains($p->name, $name)
                ));
                if (count($candidates) === 1) {
                    $link($externalId, $candidates[0], self::METHOD_NAME_CLUB);
                }
            }
        }

        return new ClMatchResult($links, $this->clubMapping($cl, $links));
    }

    /**
     * Club names differ per source ("Paris Saint-Germain" / "PSG"): the league club most of a
     * CL club's linked players play for.
     *
     * @param array<string, array<string, mixed>>                                      $cl
     * @param array<string, array{player: MergedPlayerSeason, method: string}>          $links
     *
     * @return array<string, string> CL club name => league club name
     */
    private function clubMapping(array $cl, array $links): array
    {
        $votes = [];
        foreach ($links as $externalId => $link) {
            $clClub = $cl[$externalId]['team']['name'];
            foreach ($link['player']->teams as $leagueClub) {
                $votes[$clClub][$leagueClub] = ($votes[$clClub][$leagueClub] ?? 0) + 1;
            }
        }

        $mapping = [];
        foreach ($votes as $clClub => $clubVotes) {
            arsort($clubVotes);
            // A club needs a clear majority of its CL players in one league club.
            if (reset($clubVotes) >= 3) {
                $mapping[$clClub] = (string) key($clubVotes);
            }
        }

        return $mapping;
    }

    /** "Alejandro Grimaldo" and "Álex Grimaldo" -> "a grimaldo" */
    public static function shortName(string $name): string
    {
        $parts = explode(' ', self::normalise($name));

        return ($parts[0][0] ?? '') . ' ' . end($parts);
    }

    /** True when all words of one name appear in the other. */
    private static function nameContains(string $a, string $b): bool
    {
        $wordsA = explode(' ', str_replace('-', ' ', self::normalise($a)));
        $wordsB = explode(' ', str_replace('-', ' ', self::normalise($b)));

        return array_diff($wordsA, $wordsB) === [] || array_diff($wordsB, $wordsA) === [];
    }

    public static function normalise(string $name): string
    {
        return u($name)->ascii()->lower()->replaceMatches('/[^a-z -]+/', '')->collapseWhitespace()->trim()->toString();
    }
}
