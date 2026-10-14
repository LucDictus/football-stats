<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\ApiPresenter;
use App\Entity\PlayerSeason;
use App\Entity\Season;
use App\Football\Assessment\AssessmentScope;
use App\Football\Scoring\ModelCatalog;
use App\Football\Scoring\ScoringModel;
use App\Repository\PlayerScoreRepository;
use App\Repository\ScoreRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api', name: 'api_', format: 'json')]
final class RankingController extends AbstractController
{
    private const MAX_LIMIT = 200;

    public function __construct(
        private readonly ScoreRunRepository $runs,
        private readonly PlayerScoreRepository $scores,
        private readonly ApiPresenter $presenter,
        private readonly AssessmentScope $scope,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** The released scoring models. */
    #[Route('/models', name: 'models', methods: ['GET'])]
    public function models(): JsonResponse
    {
        return $this->json([
            'default' => ModelCatalog::DEFAULT,
            'models' => array_values(array_map(static fn (ScoringModel $m) => $m->toArray(), ModelCatalog::all())),
        ]);
    }

    /**
     * The latest ranking of a model: ?model=baseline-v1&position=FW&limit=100&offset=0&unranked=1.
     */
    #[Route('/rankings', name: 'rankings', methods: ['GET'])]
    public function ranking(Request $request): JsonResponse
    {
        $modelId = $request->query->getString('model', ModelCatalog::DEFAULT);
        if (!isset(ModelCatalog::all()[$modelId])) {
            return $this->json(['error' => "Unknown model {$modelId}"], 404);
        }
        $season = $this->em->getRepository(Season::class)->findOneBy(['label' => $this->scope->season]);
        $run = $season !== null ? $this->runs->latest($modelId, $season) : null;
        if ($run === null) {
            return $this->json(['error' => "No ranking yet for {$modelId}: run app:score"], 404);
        }

        $position = $request->query->getString('position') ?: null;
        if ($position !== null && !in_array($position, PlayerSeason::POSITION_GROUPS, true)) {
            return $this->json(['error' => 'position must be one of ' . implode(', ', PlayerSeason::POSITION_GROUPS)], 400);
        }
        $limit = max(1, min(self::MAX_LIMIT, $request->query->getInt('limit', 100)));
        $offset = max(0, $request->query->getInt('offset'));
        $unranked = $request->query->getBoolean('unranked');

        return $this->json([
            'run' => $this->presenter->run($run),
            'total' => $this->scores->countForRun($run, $position, $unranked),
            'limit' => $limit,
            'offset' => $offset,
            'rows' => array_map($this->presenter->rankingRow(...), $this->scores->forRun($run, $position, $limit, $offset, $unranked)),
        ]);
    }
}
