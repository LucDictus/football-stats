<?php

declare(strict_types=1);

namespace App\DataImport\Top5;

use App\DataImport\Normalizer\SeasonBuilder;
use App\DataQuality\Top5StintMerger;
use App\Entity\ImportBatch;
use App\Entity\Player;
use App\Entity\PlayerSeason;
use App\Entity\Season;
use App\Entity\StatsScope;
use App\Football\Assessment\CompetitionCatalog;
use App\Football\Scoring\PossessionAdjustment;
use App\Football\Scoring\ScoringCandidate;
use App\Football\Standards\StandardsPlayer;

/**
 * The league seasons the standards are derived from, read from the Top-5 dataset with the same
 * merging, trust checks and roles as the season build (SeasonBuilder), but in memory: nothing
 * is stored. Each player gets his main team's goal difference per match and possession share.
 */
final readonly class Top5StandardsSample
{
    public function __construct(
        private Top5CsvReader $reader,
        private Top5StintMerger $merger,
        private Top5TeamResults $results,
    ) {
    }

    /**
     * @param string $seasonCode season value used in the CSV, e.g. '2526'
     *
     * @return list<StandardsPlayer>
     */
    public function load(string $seasonCode): array
    {
        $batch = new ImportBatch(Top5CsvReader::SOURCE, Top5CsvReader::DATASET, $seasonCode, Top5CsvReader::URL, 'standards');
        $season = new Season($seasonCode, new \DateTimeImmutable(), new \DateTimeImmutable());

        $seasons = [];
        $merged = [];
        foreach ($this->merger->merge($this->reader->read($seasonCode)['rows']) as $player) {
            $group = $player->position !== null ? substr($player->position, 0, 2) : null;
            [$role, $source] = SeasonBuilder::role($player);
            $playerSeason = new PlayerSeason(
                new Player($player->name, $player->born),
                $season,
                $player->position,
                in_array($group, PlayerSeason::POSITION_GROUPS, true) ? $group : null,
                null,
                $role,
                $source,
            );
            $playerSeason->addStats(SeasonBuilder::leagueStats($playerSeason, $player, $batch));
            $seasons[] = $playerSeason;
            $merged[] = $player;
        }
        SeasonBuilder::inferRoles($seasons);

        $candidates = [];
        foreach ($seasons as $i => $playerSeason) {
            $main = $merged[$i]->stintDetails[0] ?? null;
            $candidates[] = new ScoringCandidate(
                $i,
                $merged[$i]->name,
                $playerSeason->getPositionGroup(),
                $main['team'] ?? null,
                $playerSeason->getStats(StatsScope::League) ?? throw new \LogicException('League stats were just added'),
                $main !== null ? CompetitionCatalog::codeForTop5League($main['league']) : null,
                $playerSeason->getRole(),
            );
        }

        $possession = PossessionAdjustment::possession($candidates);
        $goalDifference = $this->results->goalDifference($seasonCode);
        $players = [];
        foreach ($candidates as $i => $c) {
            $main = $merged[$i]->stintDetails[0] ?? null;
            $teamKey = PossessionAdjustment::teamKey($c);
            $players[] = new StandardsPlayer(
                $merged[$i]->key,
                $seasonCode,
                $c,
                $main !== null ? ($goalDifference[$main['league'] . '|' . $main['team']]['goal_difference'] ?? null) : null,
                $teamKey !== null ? ($possession[$teamKey] ?? null) : null,
            );
        }

        return $players;
    }
}
