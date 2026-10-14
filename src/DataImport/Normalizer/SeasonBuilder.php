<?php

declare(strict_types=1);

namespace App\DataImport\Normalizer;

use App\DataImport\Identity\ClMatchResult;
use App\DataImport\Identity\ClPlayerMatcher;
use App\DataImport\Identity\IdentityOverrides;
use App\DataImport\Identity\IdentityRegistry;
use App\DataImport\Sofascore\SofascoreClient;
use App\DataImport\SourceName;
use App\DataImport\Top5\Top5CsvReader;
use App\DataQuality\MergedPlayerSeason;
use App\DataQuality\Top5StintMerger;
use App\Entity\Competition;
use App\Entity\ImportBatch;
use App\Entity\Player;
use App\Entity\PlayerCompetitionStats;
use App\Entity\PlayerSeason;
use App\Entity\PlayerSeasonStint;
use App\Entity\Season;
use App\Entity\StatsScope;
use App\Entity\Team;
use App\Football\Assessment\AssessmentScope;
use App\Football\Assessment\CompetitionCatalog;
use App\Football\Data\FieldCatalog;
use App\Football\Data\Origin;
use App\Football\Data\OriginCheck;
use App\Football\Data\OriginStatus;
use App\Repository\ImportBatchRepository;
use App\Repository\SourceRecordRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds the domain model of one season from the latest import batches.
 *
 * Re-running is safe: players, teams and their identifiers are reused (stable ids);
 * the season's PlayerSeason rows, stints and stats are rebuilt from scratch, in one
 * transaction, so a failed build leaves the previous one intact.
 */
final class SeasonBuilder
{
    /** @var array<string, Competition> */
    private array $competitions = [];

    /** @var array<int, PlayerSeason> spl_object_id(Player) => the season built for that player in this run */
    private array $built = [];

    /** @var array<int, string> SofaScore id => league player key (manual links) */
    private array $manual = [];

    /** @var list<string> */
    private array $rejected = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ImportBatchRepository $batches,
        private readonly SourceRecordRepository $records,
        private readonly AssessmentScope $scope,
        private readonly Top5StintMerger $merger,
        private readonly ClPlayerMatcher $matcher,
        private readonly IdentityOverrides $overrides,
        private readonly IdentityRegistry $identities,
    ) {
    }

    /** @return array<string, mixed> build summary */
    public function build(): array
    {
        $top5Batch = $this->batches->latest(Top5CsvReader::SOURCE, Top5CsvReader::DATASET, $this->scope->season)
            ?? throw new \RuntimeException('No Top-5 dataset import yet: run app:import:top5');
        $clBatch = $this->batches->latest(SofascoreClient::SOURCE, SofascoreClient::DATASET, $this->scope->season)
            ?? throw new \RuntimeException('No SofaScore CL import yet: run app:import:sofascore-cl');

        if ($top5Batch->getRecordCount() === 0 || $clBatch->getRecordCount() === 0) {
            throw new \RuntimeException('The latest league or CL batch is empty; refusing to rebuild the season from it.');
        }

        $league = $this->merger->merge($this->records->payloads($top5Batch));
        $cl = $this->records->payloads($clBatch);
        $this->manual = $this->overrides->sofascore();
        $matches = $this->matcher->match($league, $cl, $this->manual);
        $this->built = [];
        $this->rejected = [];

        return $this->em->wrapInTransaction(function () use ($league, $cl, $matches, $top5Batch, $clBatch): array {
            $season = $this->season();
            $this->ensureCompetitions();
            $this->em->flush();
            $this->em->createQuery('DELETE FROM ' . PlayerSeason::class . ' ps WHERE ps.season = :season')
                ->setParameter('season', $season)
                ->execute();
            $this->identities->load();

            $seasons = [];
            foreach ($league as $merged) {
                $seasons[$merged->key] = $this->buildLeagueSeason($merged, $season, $top5Batch);
            }
            $clOnly = 0;
            foreach ($cl as $externalId => $record) {
                $clOnly += (int) $this->buildClSeason((string) $externalId, $record, $season, $clBatch, $matches, $seasons);
            }
            $this->em->flush();

            return [
                'season' => $season->getLabel(),
                'batches' => ['league' => $top5Batch->getId(), 'cl' => $clBatch->getId()],
                'league_players' => count($league),
                'cl_players' => count($cl),
                'cl_linked' => count($matches->links),
                'cl_only' => $clOnly,
                'rejected' => $this->rejected,
            ] + $this->identities->stats();
        });
    }

    private function buildLeagueSeason(MergedPlayerSeason $merged, Season $season, ImportBatch $batch): PlayerSeason
    {
        $sofascoreId = $merged->sofascoreId !== null && $merged->check(Origin::Sofascore)->status !== OriginStatus::Suspect
            ? (string) $merged->sofascoreId
            : null;

        // A manual link sends this SofaScore id to another league player: the league row's id is wrong.
        if ($sofascoreId !== null && isset($this->manual[(int) $sofascoreId]) && $this->manual[(int) $sofascoreId] !== $merged->key) {
            $sofascoreId = null;
        }

        $player = $this->identities->player(SourceName::TOP5, $merged->key)
            ?? ($sofascoreId !== null ? $this->identities->player(SourceName::SOFASCORE, $sofascoreId) : null);
        if ($player === null || isset($this->built[spl_object_id($player)])) {
            // New, or the stored identity already belongs to another league row in this build.
            $player = $this->identities->createPlayer($merged->name, $merged->born, null);
        }
        $player->enrich($merged->name, $merged->born, null);
        $this->identities->identifyPlayer($player, SourceName::TOP5, $merged->key, 'source key');
        if ($sofascoreId !== null) {
            $this->identities->identifyPlayer($player, SourceName::SOFASCORE, $sofascoreId, 'id in league data');
        }

        $teams = [];
        foreach ($merged->stintDetails as $stint) {
            $teams[] = $this->leagueTeam($stint['team'], $stint['league']);
        }

        $group = $merged->position !== null ? substr($merged->position, 0, 2) : null;
        $playerSeason = new PlayerSeason(
            $player,
            $season,
            $merged->position,
            in_array($group, PlayerSeason::POSITION_GROUPS, true) ? $group : null,
            $teams[0] ?? null,
        );
        $this->built[spl_object_id($player)] = $playerSeason;
        foreach ($merged->stintDetails as $i => $stint) {
            $playerSeason->addStint(new PlayerSeasonStint(
                $playerSeason,
                $teams[$i],
                $this->competitions[CompetitionCatalog::codeForTop5League($stint['league'])],
                $stint['appearances'],
                $stint['starts'],
                $stint['minutes'],
            ));
        }

        $values = array_filter($merged->values, static fn ($v) => $v !== null);
        $playerSeason->addStats(new PlayerCompetitionStats(
            $playerSeason,
            StatsScope::League,
            $batch,
            $merged->minutes,
            $values,
            array_map(static fn (string $field) => FieldCatalog::get($field)->origin->value, array_combine(array_keys($values), array_keys($values))),
            array_map(static fn (OriginCheck $c) => ['status' => $c->status->value, 'minutes' => $c->minutes, 'reason' => $c->reason], $merged->checks),
        ));
        $this->em->persist($playerSeason);

        return $playerSeason;
    }

    /**
     * @param array<string, mixed>        $record  SofaScore CL record
     * @param array<string, PlayerSeason> $seasons league player key => built season
     *
     * @return bool true when the player has Champions League data only
     */
    private function buildClSeason(string $externalId, array $record, Season $season, ImportBatch $batch, ClMatchResult $matches, array $seasons): bool
    {
        if (!isset($record['minutesPlayed'])) {
            $this->rejected[] = "CL {$externalId}: no minutes";

            return false;
        }
        $sofascoreId = (string) $record['player']['id'];
        $link = $matches->linkFor($externalId);
        $playerSeason = $link !== null ? $seasons[$link['player']->key] : null;

        $player = $playerSeason?->getPlayer()
            ?? $this->identities->player(SourceName::SOFASCORE, $sofascoreId)
            ?? $this->identities->createPlayer($record['player']['name'], null, null);
        // Not linked this time but known from an earlier build: keep the identity (and its season).
        $playerSeason ??= $this->built[spl_object_id($player)] ?? null;
        if ($playerSeason?->getStats(StatsScope::ChampionsLeague) !== null) {
            $this->rejected[] = "CL {$externalId}: {$record['player']['name']} already has CL statistics (listed for two clubs?)";

            return false;
        }
        $this->identities->identifyPlayer($player, SourceName::SOFASCORE, $sofascoreId, $link['method'] ?? 'source id');

        $team = $this->clTeam($record['team'], $matches);
        $goalkeeper = $playerSeason?->isGoalkeeper() ?? SofascoreClient::looksLikeGoalkeeper($record);

        if ($playerSeason === null) {
            // CL-only: the season holds CL statistics only.
            $playerSeason = new PlayerSeason($player, $season, null, $goalkeeper ? 'GK' : null, $team);
            $this->built[spl_object_id($player)] = $playerSeason;
            $this->em->persist($playerSeason);
        }

        [$values, $origins] = $this->clValues($record, $goalkeeper);
        $minutes = (float) $record['minutesPlayed'];
        $playerSeason->addStats(new PlayerCompetitionStats(
            $playerSeason,
            StatsScope::ChampionsLeague,
            $batch,
            $minutes,
            $values,
            $origins,
            [Origin::Sofascore->value => ['status' => OriginStatus::Ok->value, 'minutes' => $minutes, 'reason' => null]],
        ));

        return $link === null;
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array{0: array<string, float>, 1: array<string, string>}
     */
    private function clValues(array $record, bool $goalkeeper): array
    {
        $values = [];
        foreach (FieldCatalog::all() as $field) {
            if ($field->clColumn === null || ($field->goalkeeper && !$goalkeeper)) {
                continue;
            }
            $value = $record[$field->clColumn] ?? null;
            // SofaScore leaves xG/xA empty when there is nothing to measure: a real zero.
            if ($value === null && $field->key === 'xg' && ($record['totalShots'] ?? null) === 0) {
                $value = 0;
            }
            if ($value === null && $field->key === 'xa' && ($record['keyPasses'] ?? null) === 0) {
                $value = 0;
            }
            if ($value !== null) {
                $values[$field->key] = (float) $value;
            }
        }
        $origins = array_fill_keys(array_keys($values), Origin::Sofascore->value);

        if (isset($values['goals'], $values['penalty_goals'])) {
            $values['np_goals'] = $values['goals'] - $values['penalty_goals'];
            $origins['np_goals'] = Origin::Derived->value;
        }
        if ($goalkeeper && isset($values['saves'], $values['goals_conceded']) && $values['saves'] + $values['goals_conceded'] > 0) {
            $values['save_pct'] = round($values['saves'] / ($values['saves'] + $values['goals_conceded']) * 100, 1);
            $origins['save_pct'] = Origin::Derived->value;
        }

        return [$values, $origins];
    }

    private function leagueTeam(string $name, string $top5League): Team
    {
        $team = $this->identities->team(SourceName::TOP5, $name)
            ?? $this->identities->createTeam($name, $this->competitions[CompetitionCatalog::codeForTop5League($top5League)]);
        $this->identities->identifyTeam($team, SourceName::TOP5, $name);

        return $team;
    }

    /** @param array{id: int, name: string} $clTeam */
    private function clTeam(array $clTeam, ClMatchResult $matches): Team
    {
        $id = (string) $clTeam['id'];
        $leagueName = $matches->clubs[$clTeam['name']] ?? null;
        $team = $this->identities->team(SourceName::SOFASCORE, $id)
            ?? ($leagueName !== null ? $this->identities->team(SourceName::TOP5, $leagueName) : null)
            ?? $this->identities->createTeam($clTeam['name'], null);
        $this->identities->identifyTeam($team, SourceName::SOFASCORE, $id);

        return $team;
    }

    private function season(): Season
    {
        $from = new \DateTimeImmutable($this->scope->periodFrom);
        $to = new \DateTimeImmutable($this->scope->periodTo);
        $season = $this->em->getRepository(Season::class)->findOneBy(['label' => $this->scope->season]);
        if ($season === null) {
            $season = new Season($this->scope->season, $from, $to);
            $this->em->persist($season);
        } else {
            $season->setPeriod($from, $to);
        }

        return $season;
    }

    private function ensureCompetitions(): void
    {
        $repository = $this->em->getRepository(Competition::class);
        foreach (CompetitionCatalog::COMPETITIONS as $code => [$name, $type, $country]) {
            $competition = $repository->findOneBy(['code' => $code]);
            if ($competition === null) {
                $competition = new Competition($code, $name, $type, $country);
                $this->em->persist($competition);
            }
            $this->competitions[$code] = $competition;
        }
    }
}
