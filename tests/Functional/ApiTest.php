<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Player;
use App\Entity\PlayerSeason;
use App\Entity\ScoreRun;
use App\Entity\Season;
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
        foreach (ModelCatalog::all() as $model) {
            self::getContainer()->get(ScoreRunner::class)->run($model);
        }
    }

    public function testModels(): void
    {
        $data = $this->getJson('/api/models');

        self::assertSame('mvp-v3', $data['default']);
        $byId = array_column($data['models'], null, 'id');
        self::assertSame('Attacking output', $byId['baseline-v1']['components'][0]['label']);
        self::assertSame('Shot stopping', $byId['positional-v1']['roles']['GK'][0]['label']);
        self::assertSame('standard', $byId['positional-v1']['normalization']);
    }

    public function testRankingListsRankedOutfieldPlayersWithComponents(): void
    {
        $data = $this->getJson('/api/rankings?model=baseline-v1');

        self::assertSame(2, $data['total'], 'the goalkeeper is not in baseline v1');
        self::assertSame('Kylian Mbappé', $data['rows'][0]['player']['name']);
        self::assertSame(1, $data['rows'][0]['rank']);
        self::assertArrayHasKey('attacking_output', $data['rows'][0]['components']);
        self::assertSame('baseline-v1', $data['run']['model']['id']);
    }

    public function testRankingFiltersAndValidates(): void
    {
        self::assertSame(['Pedri'], array_map(static fn ($r) => $r['player']['name'], $this->getJson('/api/rankings?model=baseline-v1&position=MF')['rows']));

        $this->client->request('GET', '/api/rankings?position=XX');
        self::assertResponseStatusCodeSame(400);
        $this->client->request('GET', '/api/rankings?model=nope');
        self::assertResponseStatusCodeSame(404);
    }

    public function testModelsThatGroupByRoleFilterByRole(): void
    {
        // No EA SPORTS FC positions in the fixtures, and nothing to infer from: the group's most common role.
        $pedri = $this->getJson('/api/players/' . $this->playerId('Pedri'));
        self::assertSame(['MF', 'CM', 'inferred'], [$pedri['season']['position_group'], $pedri['season']['role'], $pedri['season']['role_source']]);
        self::assertSame('CM', $pedri['score']['role']);

        self::assertSame(['Pedri'], array_map(static fn ($r) => $r['player']['name'], $this->getJson('/api/rankings?model=mvp-v3&position=CM')['rows']));
        $this->client->request('GET', '/api/rankings?model=mvp-v2&position=CM');
        self::assertResponseStatusCodeSame(400, 'mvp-v2 groups by position group, not by role');
        $this->client->request('GET', '/api/rankings?model=mvp-v3&position=MF');
        self::assertResponseStatusCodeSame(400);
    }

    public function testPlayerProfileHasStatsWithProvenanceAndScoreBreakdown(): void
    {
        $data = $this->getJson('/api/players/' . $this->playerId('Kylian Mbappé') . '?model=baseline-v1');
        $leagueGoals = array_values(array_filter($data['stats']['league']['fields'], static fn ($f) => $f['key'] === 'np_goals'))[0];

        self::assertSame('Real Madrid', $data['season']['team']);
        self::assertSame(17, (int) $leagueGoals['value']);
        self::assertSame('derived', $leagueGoals['origin']);
        self::assertSame('ok', $leagueGoals['status']);
        self::assertSame(15, (int) array_values(array_filter($data['stats']['CL']['fields'], static fn ($f) => $f['key'] === 'goals'))[0]['value']);
        self::assertSame(1, $data['score']['rank']);
        self::assertSame('np_ga_p90', $data['score']['components'][0]['metrics'][0]['key']);
        self::assertSame('percentile', $data['score']['normalization']);
        self::assertNull($data['score']['role']);
    }

    public function testGoalkeepersAreRankedOnTheirOwnComponentsInThePositionalModel(): void
    {
        $data = $this->getJson('/api/players/' . $this->playerId('Thibaut Courtois'));

        self::assertSame('GK', $data['score']['role']);
        self::assertSame('standard', $data['score']['normalization']);
        self::assertSame(['shot_stopping', 'distribution', 'reliability', 'availability'], array_column($data['score']['components'], 'key'));
        self::assertSame(3, $this->getJson('/api/rankings')['total'], 'the default model ranks every position');
    }

    public function testEachModelShowsOnlyItsOwnParts(): void
    {
        $pedri = $this->playerId('Pedri');
        $v1 = $this->getJson("/api/players/{$pedri}?model=mvp-v1")['score'];
        $v2 = $this->getJson("/api/players/{$pedri}?model=mvp-v2")['score'];

        $metrics = static fn (array $score) => array_merge(...array_map(static fn (array $c) => $c['metrics'], $score['components']));
        self::assertNotContains('errors', array_column($v1['impact']['components'], 'key'), 'mvp-v1 has no errors part');
        self::assertContains('errors', array_column($v2['impact']['components'], 'key'));
        foreach ($metrics($v1) as $metric) {
            self::assertArrayNotHasKey('possession_factor', $metric);
        }
        $tackles = array_values(array_filter($metrics($v2), static fn (array $m) => $m['key'] === 'tackles_won_p90'))[0];
        self::assertTrue($tackles['possession_adjusted'] ?? false);
    }

    public function testGoalkeeperIsNotScoredByTheBaselineModel(): void
    {
        self::assertNull($this->getJson('/api/players/' . $this->playerId('Thibaut Courtois') . '?model=baseline-v1')['score']);
    }

    public function testSearchIgnoresAccents(): void
    {
        self::assertSame(['Kylian Mbappé'], array_column($this->getJson('/api/players?q=mbappe')['players'], 'name'));
        self::assertSame([], $this->getJson('/api/players?q=m')['players'], 'at least 2 characters');
    }

    public function testUnrankedPlayersHaveNoComparableScore(): void
    {
        foreach ($this->getJson('/api/rankings?model=baseline-v1&unranked=1')['rows'] as $row) {
            self::assertSame($row['rank'] === null, $row['score'] === null, $row['player']['name']);
        }
    }

    public function testUnrankedPagingIsDeterministicAndPinnedToARun(): void
    {
        $first = $this->getJson('/api/rankings?model=baseline-v1&unranked=1&limit=1');
        $second = $this->getJson('/api/rankings?model=baseline-v1&unranked=1&limit=1&offset=1&run=' . $first['run']['id']);

        self::assertSame(2, $first['total']);
        self::assertNotSame($first['rows'][0]['player']['id'], $second['rows'][0]['player']['id']);
        self::assertSame($first['run']['id'], $second['run']['id']);
    }

    public function testSearchTreatsWildcardsAsTextAndListsAPlayerOnce(): void
    {
        // A second season for the same player must not duplicate him.
        $player = $this->em()->getRepository(Player::class)->findOneBy(['name' => 'Pedri']);
        self::assertNotNull($player);
        $previous = new Season('2024/25', new \DateTimeImmutable('2024-08-01'), new \DateTimeImmutable('2025-05-31'));
        $this->em()->persist($previous);
        $this->em()->persist(new PlayerSeason($player, $previous));
        $this->em()->flush();

        self::assertSame(['Pedri'], array_column($this->getJson('/api/players?q=pedri')['players'], 'name'));
        self::assertSame([], $this->getJson('/api/players?q=__')['players']);
        self::assertSame([], $this->getJson('/api/players?q=%25%25')['players']);
    }

    public function testARunOfAnotherSeasonIsNotServed(): void
    {
        $previous = new Season('2024/25', new \DateTimeImmutable('2024-08-01'), new \DateTimeImmutable('2025-05-31'));
        $model = ModelCatalog::get(ModelCatalog::DEFAULT);
        $oldRun = new ScoreRun($model->id(), $model->toArray(), $model->hash(), $previous, []);
        $this->em()->persist($previous);
        $this->em()->persist($oldRun);
        $this->em()->flush();

        $this->client->request('GET', '/api/rankings?run=' . $oldRun->getId());

        self::assertResponseStatusCodeSame(404);
    }

    public function testErrorsAreJson(): void
    {
        foreach (['/api/rankings?position[]=FW' => 400, '/api/rankings?limit=abc' => 400, '/api/players/1?model=nope' => 404, '/api/rankings?run=99999' => 404] as $url => $status) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame($status, $url);
            self::assertArrayHasKey('error', json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
        }
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
