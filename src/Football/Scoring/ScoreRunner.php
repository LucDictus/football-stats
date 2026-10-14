<?php

declare(strict_types=1);

namespace App\Football\Scoring;

use App\Entity\PlayerCompetitionStats;
use App\Entity\PlayerScore;
use App\Entity\ScoreRun;
use App\Entity\Season;
use App\Football\Assessment\AssessmentScope;
use App\Repository\PlayerScoreRepository;
use App\Repository\ScoreRunRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Runs a scoring model on the current season and stores the result as a ScoreRun.
 *
 * A run that would be identical to the latest one (same model definition, same import
 * batches, same scores) is not stored again: the latest run stays current.
 */
final readonly class ScoreRunner
{
    public function __construct(
        private EntityManagerInterface $em,
        private AssessmentScope $scope,
        private ScoreCalculator $calculator,
        private ScoreRunRepository $runs,
        private PlayerScoreRepository $scores,
    ) {
    }

    /** @return array{run: ScoreRun, created: bool, results: list<PlayerScoreResult>} */
    public function run(ScoringModel $model): array
    {
        $season = $this->em->getRepository(Season::class)->findOneBy(['label' => $this->scope->season])
            ?? throw new \RuntimeException("Season {$this->scope->season} is not built yet: run app:build-season");

        $candidates = $this->candidates($season, $model);
        if ($candidates === []) {
            throw new \RuntimeException("No {$model->scope->value} statistics for {$season->getLabel()}: run app:build-season");
        }
        $results = $this->calculator->score($model, $candidates);
        $batchIds = array_values(array_unique(array_map(static fn (ScoringCandidate $c) => (int) $c->stats->getBatch()->getId(), $candidates)));
        sort($batchIds);

        $latest = $this->runs->latest($model->id(), $season);
        if ($latest !== null && $this->isSameRun($latest, $model, $batchIds, $results)) {
            return ['run' => $latest, 'created' => false, 'results' => $results];
        }

        $run = new ScoreRun($model->id(), $model->toArray(), $model->hash(), $season, $batchIds);
        $this->em->wrapInTransaction(function () use ($run, $results): void {
            $ranked = 0;
            $this->em->persist($run);
            foreach ($results as $result) {
                $ranked += (int) ($result->rank !== null);
                $this->em->persist(new PlayerScore(
                    $run,
                    $result->candidate->stats->getPlayerSeason()->getPlayer(),
                    $result->rank,
                    $result->score,
                    $result->confidence,
                    $result->candidate->positionGroup,
                    $result->candidate->team,
                    $result->candidate->stats->getMinutes(),
                    $result->components,
                ));
            }
            $run->setCounts($ranked, count($results) - $ranked);
        });

        return ['run' => $run, 'created' => true, 'results' => $results];
    }

    /** @return list<ScoringCandidate> */
    private function candidates(Season $season, ScoringModel $model): array
    {
        /** @var list<PlayerCompetitionStats> $stats */
        $stats = $this->em->createQueryBuilder()
            ->select('s', 'ps', 'p', 't')
            ->from(PlayerCompetitionStats::class, 's')
            ->join('s.playerSeason', 'ps')
            ->join('ps.player', 'p')
            ->leftJoin('ps.mainTeam', 't')
            ->where('ps.season = :season AND s.scope = :scope')
            ->setParameter('season', $season)
            ->setParameter('scope', $model->scope)
            ->getQuery()
            ->getResult();

        return array_map(static fn (PlayerCompetitionStats $s) => new ScoringCandidate(
            (int) $s->getPlayerSeason()->getPlayer()->getId(),
            $s->getPlayerSeason()->getPlayer()->getName(),
            $s->getPlayerSeason()->getPositionGroup(),
            $s->getPlayerSeason()->getMainTeam()?->getName(),
            $s,
        ), $stats);
    }

    /**
     * @param list<int>               $batchIds
     * @param list<PlayerScoreResult> $results
     */
    private function isSameRun(ScoreRun $latest, ScoringModel $model, array $batchIds, array $results): bool
    {
        if ($latest->getDefinitionHash() !== $model->hash() || $latest->getBatchIds() !== $batchIds) {
            return false;
        }
        $stored = [];
        foreach ($this->scores->findBy(['run' => $latest]) as $score) {
            $stored[(int) $score->getPlayer()->getId()] = [$score->getRank(), $score->getScore()];
        }
        if (count($stored) !== count($results)) {
            return false;
        }
        foreach ($results as $result) {
            $previous = $stored[$result->candidate->playerId] ?? null;
            if ($previous === null || $previous[0] !== $result->rank || abs((float) $previous[1] - (float) $result->score) > 1e-9) {
                return false;
            }
        }

        return true;
    }
}
