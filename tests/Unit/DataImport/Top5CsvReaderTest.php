<?php

declare(strict_types=1);

namespace App\Tests\Unit\DataImport;

use App\DataImport\Top5\Top5CsvReader;
use App\Tests\Support\SourceFixtures;
use PHPUnit\Framework\TestCase;

final class Top5CsvReaderTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/top5-' . bin2hex(random_bytes(4));
        mkdir($this->directory . '/data/master', 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->directory . '/data/master/player_seasons.csv');
        @rmdir($this->directory . '/data/master');
        @rmdir($this->directory . '/data');
        @rmdir($this->directory);
    }

    public function testReadsTheSeasonAndRejectsBrokenAndDuplicateRows(): void
    {
        $row = SourceFixtures::top5Row(['player' => 'A', 'team' => 'X']);
        $this->writeCsv(array_keys($row), [
            array_values($row),
            array_values(['season' => '2425'] + $row),     // other season: skipped, not rejected
            array_values($row),                             // duplicate
            ['too', 'few', 'columns'],
            array_values(['player' => 'B'] + $row),
        ]);

        $result = (new Top5CsvReader($this->directory))->read('2526');

        self::assertSame(['A|1995|X|ENG-Premier League', 'B|1995|X|ENG-Premier League'], array_keys($result['rows']));
        self::assertCount(2, $result['rejected']);
        self::assertStringContainsString('duplicate', $result['rejected'][0]);
        self::assertStringContainsString('3 columns', $result['rejected'][1]);
    }

    public function testFailsWhenTheDatasetLostAColumn(): void
    {
        $row = SourceFixtures::top5Row();
        unset($row['us_xg']);
        $this->writeCsv(array_keys($row), [array_values($row)]);

        $this->expectExceptionMessage('us_xg');
        (new Top5CsvReader($this->directory))->read('2526');
    }

    public function testFailsWithAHelpfulMessageWhenNotCloned(): void
    {
        $this->expectExceptionMessage('Clone the dataset first');
        (new Top5CsvReader($this->directory . '/nope'))->read('2526');
    }

    /**
     * @param list<string>       $header
     * @param list<list<string>> $lines
     */
    private function writeCsv(array $header, array $lines): void
    {
        $fh = fopen($this->directory . '/data/master/player_seasons.csv', 'w');
        self::assertNotFalse($fh);
        fputcsv($fh, $header, escape: '');
        foreach ($lines as $line) {
            fputcsv($fh, $line, escape: '');
        }
        fclose($fh);
    }
}
