<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\StatsExplorer;
use App\Football\Data\RoleCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api', name: 'api_', format: 'json')]
final class StatsController extends AbstractController
{
    private const DEFAULT_MIN_MINUTES = 900;
    private const MAX_MIN_MINUTES = 3000;

    public function __construct(
        private readonly ApiContext $context,
        private readonly StatsExplorer $explorer,
    ) {
    }

    /**
     * Two statistics against each other for every league player of the season (the stat explorer):
     * ?x=np_xg&y=xa&per=90|total&min_minutes=900&model=&run=.
     * x and y: a statistic of the data, or a part of the model's score (score, positional,
     * contribution, goals_above_replacement; ranked players only). Players without a value on
     * an axis are left out and counted per role in `unknown`. Filtering by role or line is left to the
     * client: every row carries both.
     */
    #[Route('/stats', name: 'stats', methods: ['GET'])]
    public function stats(Request $request): JsonResponse
    {
        $modelId = $this->context->modelId($request);
        $x = $this->context->string($request, 'x') ?? 'np_xg';
        $y = $this->context->string($request, 'y') ?? 'xa';
        foreach (['x' => $x, 'y' => $y] as $name => $key) {
            if (!StatsExplorer::isAxis($key, true)) {
                throw new BadRequestHttpException("{$name} must be a statistic (see axes): {$key} is not one");
            }
        }
        $per = $this->context->string($request, 'per') ?? StatsExplorer::PER_90;
        if (!in_array($per, [StatsExplorer::PER_90, StatsExplorer::PER_TOTAL], true)) {
            throw new BadRequestHttpException('per must be 90 or total');
        }
        $minMinutes = $this->context->int($request, 'min_minutes', self::DEFAULT_MIN_MINUTES);
        if ($minMinutes < 0 || $minMinutes > self::MAX_MIN_MINUTES) {
            throw new BadRequestHttpException('min_minutes must be between 0 and ' . self::MAX_MIN_MINUTES);
        }
        $run = $this->context->run($request, $modelId);
        $rows = $this->explorer->rows($this->context->season(), $run, $x, $y, $per, $minMinutes);

        return $this->json([
            'model_id' => $modelId,
            'run_id' => $run?->getId(),
            'x' => $x,
            'y' => $y,
            'per' => $per,
            'min_minutes' => $minMinutes,
            'axes' => StatsExplorer::axes($run !== null),
            'roles' => array_map(static fn (array $r) => $r['label'], RoleCatalog::ROLES),
            'lines' => RoleCatalog::LINES,
            'unknown' => $rows['unknown'],
            'players' => $rows['players'],
        ]);
    }
}
