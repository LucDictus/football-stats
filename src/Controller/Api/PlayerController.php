<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\ApiPresenter;
use App\Entity\Player;
use App\Entity\PlayerSeason;
use App\Entity\StatsScope;
use App\Repository\PlayerScoreRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/players', name: 'api_players_', format: 'json')]
final class PlayerController extends AbstractController
{
    private const SEARCH_LIMIT = 20;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ApiContext $context,
        private readonly PlayerScoreRepository $scores,
        private readonly ApiPresenter $presenter,
    ) {
    }

    /** Name search for the search box (?q=mbap): players of the current season, accents ignored. */
    #[Route('', name: 'search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $query = trim($this->context->string($request, 'q') ?? '');
        if (mb_strlen($query) < 2) {
            return $this->json(['players' => []]);
        }

        $rows = $this->em->getConnection()->fetchAllAssociative(
            "SELECT p.id, p.name, ps.position_group, t.name AS team
             FROM player p
             JOIN player_season ps ON ps.player_id = p.id AND ps.season_id = :season
             LEFT JOIN team t ON t.id = ps.main_team_id
             LEFT JOIN player_competition_stats l ON l.player_season_id = ps.id AND l.scope = 'league'
             WHERE unaccent_lower(p.name) LIKE '%' || unaccent_lower(:q) || '%' ESCAPE '!'
             -- names starting with the query first, then the most-played players
             ORDER BY unaccent_lower(p.name) NOT LIKE unaccent_lower(:q) || '%' ESCAPE '!', l.minutes DESC NULLS LAST, p.name, p.id
             LIMIT " . self::SEARCH_LIMIT,
            // % and _ in the query are letters, not wildcards.
            ['q' => strtr($query, ['!' => '!!', '%' => '!%', '_' => '!_']), 'season' => $this->context->season()->getId()],
        );

        return $this->json(['players' => array_map(static fn (array $r) => [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'position' => $r['position_group'],
            'team' => $r['team'],
        ], $rows)]);
    }

    /** Profile: season, statistics with provenance per scope, and the score breakdown (?model=). */
    #[Route('/{id<\d+>}', name: 'show', methods: ['GET'])]
    public function show(int $id, Request $request): JsonResponse
    {
        $modelId = $this->context->modelId($request);
        $player = $this->em->find(Player::class, $id);
        $playerSeason = $player !== null
            ? $this->em->getRepository(PlayerSeason::class)->findOneBy(['player' => $player, 'season' => $this->context->season()])
            : null;
        if ($player === null || $playerSeason === null) {
            throw new NotFoundHttpException('No such player in this season');
        }

        $run = $this->context->run($request, $modelId);
        $score = $run !== null ? $this->scores->forPlayer($run, $player) : null;

        $stats = [];
        foreach (StatsScope::cases() as $scope) {
            $scopeStats = $playerSeason->getStats($scope);
            $stats[$scope->value] = $scopeStats !== null ? $this->presenter->stats($scopeStats, $playerSeason->isGoalkeeper()) : null;
        }

        return $this->json([
            'player' => ['id' => $player->getId(), 'name' => $player->getName(), 'birth_year' => $player->getBirthYear()],
            'season' => $this->presenter->season($playerSeason),
            'stats' => $stats,
            'score' => $score !== null ? $this->presenter->scoreBreakdown($score) : null,
        ]);
    }
}
