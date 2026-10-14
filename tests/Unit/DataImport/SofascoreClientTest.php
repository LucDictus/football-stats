<?php

declare(strict_types=1);

namespace App\Tests\Unit\DataImport;

use App\DataImport\Sofascore\SofascoreClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class SofascoreClientTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/sofascore-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testReadsAllPagesInOrder(): void
    {
        $this->page(1, 2, [['player' => ['id' => 1]]]);
        $this->page(2, 2, [['player' => ['id' => 2]]]);

        $results = (new SofascoreClient())->readSnapshot($this->directory);

        self::assertSame([1, 2], array_map(static fn (array $r) => $r['player']['id'], $results));
    }

    public function testRefusesAnIncompleteSnapshot(): void
    {
        $this->page(1, 3, []);
        $this->page(2, 3, []);

        $this->expectExceptionMessage('2 of 3 pages');
        (new SofascoreClient())->readSnapshot($this->directory);
    }

    public function testGoalkeeperHeuristic(): void
    {
        self::assertTrue(SofascoreClient::looksLikeGoalkeeper(['saves' => 36, 'goalsPrevented' => 5.31]));
        self::assertTrue(SofascoreClient::looksLikeGoalkeeper(['saves' => 0, 'goalsPrevented' => -0.4]));
        self::assertFalse(SofascoreClient::looksLikeGoalkeeper(['saves' => 0, 'goalsPrevented' => null]));
    }

    /** @param list<array<string, mixed>> $results */
    private function page(int $page, int $pages, array $results): void
    {
        file_put_contents("{$this->directory}/page-{$page}.json", json_encode(['results' => $results, 'page' => $page, 'pages' => $pages]));
    }
}
