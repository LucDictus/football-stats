<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** The inspector pages render (on whatever the test database holds). */
final class InspectorTest extends WebTestCase
{
    #[DataProvider('pages')]
    public function testPageRenders(string $url): void
    {
        $client = self::createClient();
        $client->request('GET', $url);

        self::assertResponseIsSuccessful();
    }

    /** @return iterable<array{string}> */
    public static function pages(): iterable
    {
        yield ['/inspector'];
        yield ['/inspector/coverage'];
        yield ['/inspector/players'];
        yield ['/inspector/players?q=mbappe&filter=flagged&sort=cl_goals'];
    }

    public function testUnknownPlayerIs404(): void
    {
        $client = self::createClient();
        $client->request('GET', '/inspector/players/999999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testHomeRedirectsToInspector(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertResponseRedirects('/inspector');
    }
}
