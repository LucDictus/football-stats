<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\ApiPresenter;
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
        // A position group (GK, DF, MF, FW), or a role for models that group by role.
        $groups = ModelCatalog::get($modelId)->positionGroups;
        $position = $this->context->string($request, 'position');
        if ($position !== null && !in_array($position, $groups, true)) {
            throw new BadRequestHttpException("position must be one of {$modelId}'s groups: " . implode(', ', $groups));
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

    /**
     * How a part of the score is spread over a group: every ranked player's points and, for
     * metrics and the contribution, the value behind them. Positional, component and metric
     * points: 50 = the group's average, 10 = one standard deviation (standard models); the
     * contribution likewise over all ranked players; the final score of blended models is their
     * weighted sum (about.scale = blend).
     * ?model=&run=&position=W&part=metric&component=np_xg&metric=np_xg
     * part: final | positional | contribution (position optional: all groups) | component | metric.
     */
    #[Route('/distribution', name: 'distribution', methods: ['GET'])]
    public function distribution(Request $request): JsonResponse
    {
        $modelId = $this->context->modelId($request);
        $model = ModelCatalog::get($modelId);
        $position = $this->context->string($request, 'position');
        $part = $this->context->string($request, 'part') ?? 'final';
        // Final score and its halves compare across groups too; components and metrics only within one.
        if (($position !== null || in_array($part, ['component', 'metric'], true)) && !in_array($position, $model->positionGroups, true)) {
            throw new BadRequestHttpException("position must be one of {$modelId}'s groups: " . implode(', ', $model->positionGroups));
        }
        $componentKey = $this->context->string($request, 'component');
        $metricKey = $this->context->string($request, 'metric');

        $components = $position === null ? [] : array_column(array_map(static fn ($c) => ['k' => $c->key, 'c' => $c], $model->componentsFor($model->comparesWithinRole() ? $position : null)), 'c', 'k');
        $about = match ($part) {
            'final' => ['label' => 'Final score', 'scale' => $model->impact !== null ? 'blend' : 'score'],
            'positional' => ['label' => 'Positional excellence', 'scale' => 'standard'],
            'contribution' => $model->impact !== null ? ['label' => 'Contribution', 'unit' => 'goals above replacement'] : throw new BadRequestHttpException("{$modelId} has no contribution"),
            'component', 'metric' => self::componentInfo($components, $componentKey, $part === 'metric' ? $metricKey : null),
            default => throw new BadRequestHttpException('part must be final, positional, contribution, component or metric'),
        };

        $run = $this->context->run($request, $modelId)
            ?? throw new NotFoundHttpException("No ranking yet for {$modelId}: run app:score");

        return $this->json([
            'run_id' => $run->getId(),
            'model_id' => $modelId,
            'position' => $position,
            'part' => $part,
            'about' => $about,
            'players' => $this->scores->distribution($run, $position, $part, $componentKey, $metricKey),
        ]);
    }

    /**
     * @param array<string, \App\Football\Scoring\Component> $components
     *
     * @return array<string, mixed>
     */
    private static function componentInfo(array $components, ?string $componentKey, ?string $metricKey): array
    {
        $component = $components[(string) $componentKey] ?? throw new BadRequestHttpException('component must be one of: ' . implode(', ', array_keys($components)));
        $info = ['label' => $component->label, 'weight' => $component->weight, 'component' => $component->key];
        if ($metricKey === null) {
            return $info;
        }
        foreach ($component->metrics as $metric) {
            if ($metric->key === $metricKey) {
                return ['label' => $metric->label, 'component_label' => $component->label, 'weight' => $component->weight * $metric->weight, 'metric' => $metric->toArray()] + $info;
            }
        }
        throw new BadRequestHttpException('metric must be one of: ' . implode(', ', array_map(static fn ($m) => $m->key, $component->metrics)));
    }
}
