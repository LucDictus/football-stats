<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Player;
use App\Entity\PlayerIdentifier;
use App\Entity\PlayerSeason;
use App\Entity\StatsScope;
use App\Football\Data\FieldCatalog;
use App\Inspector\InspectorQueries;
use Doctrine\ORM\EntityManagerInterface;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Data inspector: a read-only developer tool to look at what the pipeline produced.
 * Not the product UI (that will be a React app on a JSON API).
 */
#[Route('/inspector', name: 'inspector_')]
final class InspectorController extends AbstractController
{
    private const MIN_MINUTES = 900;

    public function __construct(
        private readonly InspectorQueries $queries,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'overview')]
    public function overview(): Response
    {
        $statuses = [];
        foreach ($this->queries->originStatuses(self::MIN_MINUTES) as $row) {
            $statuses[$row['origin']][$row['status']] = $row['players'];
        }

        return $this->render('inspector/overview.html.twig', [
            'counts' => $this->queries->counts(),
            'batches' => $this->queries->batches(),
            'statuses' => $statuses,
            'methods' => $this->queries->identifierMethods(),
            'min_minutes' => self::MIN_MINUTES,
        ]);
    }

    #[Route('/coverage', name: 'coverage')]
    public function coverage(#[Autowire('%kernel.project_dir%/docs/data-coverage.md')] string $report): Response
    {
        // Our own generated file, so rendering it as HTML is safe.
        return $this->render('inspector/coverage.html.twig', [
            'html' => is_file($report) ? (new GithubFlavoredMarkdownConverter())->convert((string) file_get_contents($report)) : null,
        ]);
    }

    #[Route('/players', name: 'players')]
    public function players(Request $request): Response
    {
        $search = trim($request->query->getString('q'));
        $filter = $request->query->getString('filter', 'all');
        $sort = $request->query->getString('sort', 'league_goals');
        $filter = in_array($filter, InspectorQueries::FILTERS, true) ? $filter : 'all';
        $sort = in_array($sort, InspectorQueries::SORTS, true) ? $sort : 'league_goals';

        return $this->render('inspector/players.html.twig', [
            'players' => $this->queries->players($search, $filter, $sort),
            'q' => $search,
            'filter' => $filter,
            'sort' => $sort,
        ]);
    }

    #[Route('/players/{id<\d+>}', name: 'player')]
    public function player(int $id): Response
    {
        $player = $this->em->find(Player::class, $id) ?? throw new NotFoundHttpException('No such player');

        return $this->render('inspector/player.html.twig', [
            'player' => $player,
            'identifiers' => $this->em->getRepository(PlayerIdentifier::class)->findBy(['player' => $player]),
            'seasons' => $this->em->getRepository(PlayerSeason::class)->findBy(['player' => $player]),
            'scopes' => StatsScope::cases(),
            'fields' => FieldCatalog::all(),
        ]);
    }
}
