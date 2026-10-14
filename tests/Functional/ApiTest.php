<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Player;
use App\Football\Scoring\ModelCatalog;
use App\Football\Scoring\ScoreRunner;
use App\Tests\Support\PipelineTrait;
use App\Tests\Support\SourceFixtures as F;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiTest extends WebTestCase
{
    use PipelineTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->resetDatabase();
        $this->importTop5([
            F::top5Row(['player' => 'Kylian Mbappé', 'born' => '1998', 'team' => 'Real Madrid', 'league' => 'ESP-La Liga', 'fb_gls' => '25', 'ss_goals' => '25', 'ss_penaltyGoals' => '8', 'ss_player_id' => '826643.0']),
            F::top5Row(['player' => 'Pedri', 'born' => '2002', 'team' => 'Barcelona', 'league' => 'ESP-La Liga', 'pos' => 'MF', 'fb_gls' => '4', 'ss_goals' => '4', 'ss_penaltyGoals' => '0']),
            F::top5Row(['player' => 'Thibaut Courtois', 'born' => '1992', 'team' => 'Real Madrid', 'league' => 'ESP-La Liga', 'pos' => 'GK']),
        ]);
        $this->importCl([F::sofascoreRecord(826643, 'Kylian Mbappé', 2829, 'Real Madrid', ['goals' => 15])]);
        $this->build();
        self::getContainer()->get(ScoreRunner::class)->run(ModelCatalog::get(ModelCatalog::DEFAULT));
    }

    public function testModels(): void
    {
        $data = $this->getJson('/api/models');

        self::assertSame('baseline-v1', $data['default']);
        self::assertSame('Attacking output', $data['models'][0]['components'][0]['label']);
    }

    public function testRankingListsRankedOutfieldPlayersWithComponents(): void
    {
        $data = $this->getJson('/api/rankings');

        self::assertSame(2, $data['total'], 'the goalkeeper is not in baseline v1');
        self::assertSame('Kylian Mbappé', $data['rows'][0]['player']['name']);
        self::assertSame(1, $data['rows'][0]['rank']);
        self::assertArrayHasKey('attacking_output', $data['rows'][0]['components']);
        self::assertSame('baseline-v1', $data['run']['model']['id']);
    }

    public function testRankingFiltersAndValidates(): void
    {
        self::assertSame(['Pedri'], array_map(static fn ($r) => $r['player']['name'], $this->getJson('/api/rankings?position=MF')['rows']));

        $this->client->request('GET', '/api/rankings?position=XX');
        self::assertResponseStatusCodeSame(400);
        $this->client->request('GET', '/api/rankings?model=nope');
        self::assertResponseStatusCodeSame(404);
    }

    public function testPlayerProfileHasStatsWithProvenanceAndScoreBreakdown(): void
    {
        $data = $this->getJson('/api/players/' . $this->playerId('Kylian Mbappé'));
        $leagueGoals = array_values(array_filter($data['stats']['league']['fields'], static fn ($f) => $f['key'] === 'np_goals'))[0];

        self::assertSame('Real Madrid', $data['season']['team']);
        self::assertSame(17, (int) $leagueGoals['value']);
        self::assertSame('derived', $leagueGoals['origin']);
        self::assertSame('ok', $leagueGoals['status']);
        self::assertSame(15, (int) array_values(array_filter($data['stats']['CL']['fields'], static fn ($f) => $f['key'] === 'goals'))[0]['value']);
        self::assertSame(1, $data['score']['rank']);
        self::assertSame('np_ga_p90', $data['score']['components'][0]['metrics'][0]['key']);
    }

    public function testGoalkeeperProfileHasNoScoreYet(): void
    {
        $data = $this->getJson('/api/players/' . $this->playerId('Thibaut Courtois'));

        self::assertNull($data['score']);
        self::assertSame('GK', $data['season']['position_group']);
    }

    public function testSearchIgnoresAccents(): void
    {
        self::assertSame(['Kylian Mbappé'], array_column($this->getJson('/api/players?q=mbappe')['players'], 'name'));
        self::assertSame([], $this->getJson('/api/players?q=m')['players'], 'at least 2 characters');
    }

    public function testUnknownPlayerIs404(): void
    {
        $this->client->request('GET', '/api/players/999999');

        self::assertResponseStatusCodeSame(404);
    }

    /** @return array<string, mixed> */
    private function getJson(string $url): array
    {
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function playerId(string $name): int
    {
        return (int) $this->em()->getRepository(Player::class)->findOneBy(['name' => $name])?->getId();
    }
}
