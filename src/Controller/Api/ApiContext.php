<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\ScoreRun;
use App\Entity\Season;
use App\Football\Assessment\AssessmentScope;
use App\Football\Scoring\ModelCatalog;
use App\Repository\ScoreRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Resolves what every API request is about: the season, the model and the score run. */
final readonly class ApiContext
{
    public function __construct(
        private EntityManagerInterface $em,
        private AssessmentScope $scope,
        private ScoreRunRepository $runs,
    ) {
    }

    public function season(): Season
    {
        return $this->em->getRepository(Season::class)->findOneBy(['label' => $this->scope->season])
            ?? throw new NotFoundHttpException("Season {$this->scope->season} is not built yet");
    }

    /** ?model= (default model when absent); 404 for an unknown model. */
    public function modelId(Request $request): string
    {
        $modelId = $this->string($request, 'model') ?? ModelCatalog::DEFAULT;
        if (!isset(ModelCatalog::all()[$modelId])) {
            throw new NotFoundHttpException("Unknown model {$modelId}");
        }

        return $modelId;
    }

    /**
     * ?run= pins a specific run of the model in the current season (so paging never mixes
     * two runs); otherwise the latest run. Null when the model has no run yet.
     */
    public function run(Request $request, string $modelId): ?ScoreRun
    {
        $runId = $this->string($request, 'run');
        if ($runId === null) {
            return $this->runs->latest($modelId, $this->season());
        }
        if (!ctype_digit($runId)) {
            throw new BadRequestHttpException('run must be a run id (a number)');
        }
        $run = $this->runs->find((int) $runId);
        if ($run === null || $run->getModelId() !== $modelId || $run->getSeason()->getId() !== $this->season()->getId()) {
            throw new NotFoundHttpException("No run {$runId} of {$modelId}");
        }

        return $run;
    }

    /** A scalar query parameter, or null when absent or empty; 400 for arrays (?x[]=1). */
    public function string(Request $request, string $name): ?string
    {
        $value = $request->query->all()[$name] ?? null;
        if (is_array($value)) {
            throw new BadRequestHttpException("{$name} must be a single value");
        }

        return $value === null || $value === '' ? null : (string) $value;
    }

    public function int(Request $request, string $name, int $default): int
    {
        $value = $this->string($request, $name);
        if ($value === null) {
            return $default;
        }
        if (!preg_match('/^-?\d+$/', $value)) {
            throw new BadRequestHttpException("{$name} must be a whole number");
        }

        return (int) $value;
    }
}
