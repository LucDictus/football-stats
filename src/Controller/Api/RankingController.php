<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\ApiPresenter;
use App\Entity\PlayerSeason;
use App\Football\Scoring\ModelCatalog;
use App\Football\Scoring\ScoringModel;
use App\Repository\PlayerScoreRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api', name: 'api_', format: 'json')]
final class RankingController extends AbstractController
{
    private const MAX_LIMIT = 200;

    public function __construct(
        private readonly ApiContext $context,
        private readonly PlayerScoreRepository $scores,
        private readonly ApiPresenter $presenter,
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
     * A ranking: ?model=baseline-v1&run=2&position=FW&limit=100&offset=0&unranked=1.
     * Without run: the latest run of the model. Pass the run id of the first page when paging.
     */
    #[Route('/rankings', name: 'rankings', methods: ['GET'])]
    public function ranking(Request $request): JsonResponse
    {
        // Parameters first: a malformed request is a 400 whatever the data.
        $modelId = $this->context->modelId($request);
        $position = $this->context->string($request, 'position');
        if ($position !== null && !in_array($position, PlayerSeason::POSITION_GROUPS, true)) {
            throw new BadRequestHttpException('position must be one of ' . implode(', ', PlayerSeason::POSITION_GROUPS));
        }
        $limit = max(1, min(self::MAX_LIMIT, $this->context->int($request, 'limit', 100)));
        $offset = max(0, $this->context->int($request, 'offset', 0));
        $unranked = filter_var($this->context->string($request, 'unranked') ?? '0', FILTER_VALIDATE_BOOL);

        $run = $this->context->run($request, $modelId)
            ?? throw new NotFoundHttpException("No ranking yet for {$modelId}: run app:score");

        return $this->json([
            'run' => $this->presenter->run($run),
            'total' => $this->scores->countForRun($run, $position, $unranked),
            'limit' => $limit,
            'offset' => $offset,
            'rows' => array_map($this->presenter->rankingRow(...), $this->scores->forRun($run, $position, $limit, $offset, $unranked)),
        ]);
    }
}
