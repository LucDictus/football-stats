<?php

declare(strict_types=1);

namespace App\DataImport\Identity;

use App\Entity\Competition;
use App\Entity\Player;
use App\Entity\PlayerIdentifier;
use App\Entity\Team;
use App\Entity\TeamIdentifier;
use Doctrine\ORM\EntityManagerInterface;

/**
 * In-memory index of all player and team identifiers for one build.
 *
 * Looking up an identifier before creating anything is what keeps ids stable: a player
 * imported again is found through the identifiers stored the first time.
 */
final class IdentityRegistry
{
    /** @var array<string, PlayerIdentifier> "source|external id" => identifier */
    private array $players = [];
    /** @var array<string, TeamIdentifier> */
    private array $teams = [];

    /** @var array{players_created: int, teams_created: int, identifiers_added: int, relinked: list<string>} */
    private array $stats = ['players_created' => 0, 'teams_created' => 0, 'identifiers_added' => 0, 'relinked' => []];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function load(): void
    {
        $this->players = $this->teams = [];
        $this->stats = ['players_created' => 0, 'teams_created' => 0, 'identifiers_added' => 0, 'relinked' => []];
        foreach ($this->em->getRepository(PlayerIdentifier::class)->findAll() as $identifier) {
            $this->players[$identifier->getSource() . '|' . $identifier->getExternalId()] = $identifier;
        }
        foreach ($this->em->getRepository(TeamIdentifier::class)->findAll() as $identifier) {
            $this->teams[$identifier->getSource() . '|' . $identifier->getExternalId()] = $identifier;
        }
    }

    public function player(string $source, string $externalId): ?Player
    {
        return ($this->players[$source . '|' . $externalId] ?? null)?->getPlayer();
    }

    public function createPlayer(string $name, ?int $birthYear, ?string $nationality): Player
    {
        $player = new Player($name, $birthYear, $nationality);
        $this->em->persist($player);
        ++$this->stats['players_created'];

        return $player;
    }

    /**
     * Records that a source id refers to this player. An identifier that pointed to another
     * player is moved: the current matching (better rules, a manual link) wins.
     */
    public function identifyPlayer(Player $player, string $source, string $externalId, string $method): void
    {
        $key = $source . '|' . $externalId;
        $existing = $this->players[$key] ?? null;
        if ($existing !== null && $existing->getPlayer() === $player) {
            return;
        }
        if ($existing !== null) {
            $this->stats['relinked'][] = sprintf('%s %s: %s -> %s', $source, $externalId, $existing->getPlayer()->getName(), $player->getName());
            $this->em->remove($existing);
            $this->em->flush(); // the unique (source, external id) must be free before the new row is inserted
        }
        $this->players[$key] = new PlayerIdentifier($player, $source, $externalId, $method);
        $this->em->persist($this->players[$key]);
        ++$this->stats['identifiers_added'];
    }

    public function team(string $source, string $externalId): ?Team
    {
        return ($this->teams[$source . '|' . $externalId] ?? null)?->getTeam();
    }

    public function createTeam(string $name, ?Competition $domesticLeague): Team
    {
        $team = new Team($name, $domesticLeague);
        $this->em->persist($team);
        ++$this->stats['teams_created'];

        return $team;
    }

    public function identifyTeam(Team $team, string $source, string $externalId): void
    {
        $key = $source . '|' . $externalId;
        if (isset($this->teams[$key])) {
            return;
        }
        $this->teams[$key] = new TeamIdentifier($team, $source, $externalId);
        $this->em->persist($this->teams[$key]);
    }

    /** @return array{players_created: int, teams_created: int, identifiers_added: int, relinked: list<string>} */
    public function stats(): array
    {
        return $this->stats;
    }
}
