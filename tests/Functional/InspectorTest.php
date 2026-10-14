<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Player;
use App\Tests\Support\PipelineTrait;
use App\Tests\Support\SourceFixtures as F;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** The inspector pages render, on a small season built by the real pipeline. */
final class InspectorTest extends WebTestCase
{
    use PipelineTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->resetDatabase();
        $this->importTop5([
            F::top5Row(['player' => 'Piero Hincapié', 'born' => '2002', 'team' => 'Arsenal', 'playing_time_min' => '1792', 'playing_time_mp' => '20', 'ss_minutesPlayed' => '90', 'ss_player_id' => '1002837.0']),
            F::top5Row(['player' => 'Piero Hincapié', 'born' => '2002', 'team' => 'Leverkusen', 'league' => 'GER-Bundesliga', 'playing_time_min' => '90', 'playing_time_mp' => '1', 'ss_minutesPlayed' => '90', 'ss_player_id' => '1002837.0']),
        ]);
        $this->importCl([F::sofascoreRecord(1002837, 'Piero Hincapié', 42, 'Arsenal', ['tackles' => 21])]);
        $this->build();
    }

    #[DataProvider('pages')]
    public function testPageRenders(string $url, string $expected): void
    {
        $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', $expected);
    }

    /** @return iterable<array{string, string}> */
    public static function pages(): iterable
    {
        yield 'overview' => ['/inspector', 'Import batches'];
        yield 'coverage' => ['/inspector/coverage', 'Data coverage report'];
        yield 'search ignores accents' => ['/inspector/players?q=hincapie', 'Piero Hincapié'];
        yield 'flagged filter' => ['/inspector/players?filter=flagged&sort=cl_goals', 'Piero Hincapié'];
        yield 'unknown filter falls back' => ['/inspector/players?filter=drop+table&sort=nope', 'Piero Hincapié'];
    }

    public function testPlayerPageShowsProvenanceAndTrust(): void
    {
        $player = $this->em()->getRepository(Player::class)->findOneBy(['name' => 'Piero Hincapié']);
        self::assertNotNull($player);

        $crawler = $this->client->request('GET', '/inspector/players/' . $player->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'covers 90 of 1882 minutes');
        self::assertSelectorTextContains('main', 'Champions League');
        self::assertCount(2, $crawler->filter('h3'), 'league and CL sections');
    }

    public function testUnknownPlayerIs404(): void
    {
        $this->client->request('GET', '/inspector/players/999999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testHomeRedirectsToInspector(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseRedirects('/inspector');
    }
}
