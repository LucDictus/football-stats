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
            $row,
            ['season' => '2425'] + $row,     // other season: skipped, not rejected
            $row,                            // duplicate
            ['too', 'few', 'columns'],
            ['player' => 'B'] + $row,
        ]);

        $result = (new Top5CsvReader($this->directory))->read('2526');

        self::assertSame(['A|1995|X|ENG-Premier League', 'B|1995|X|ENG-Premier League'], array_keys($result['rows']));
        self::assertCount(2, $result['rejected']);
        self::assertStringContainsString('duplicate', $result['rejected'][0]);
        self::assertStringContainsString('3 columns', $result['rejected'][1]);
    }

    public function testAPlayerWithAStintWithoutMinutesIsRejectedAsAWhole(): void
    {
        $arsenal = SourceFixtures::top5Row(['player' => 'A', 'team' => 'Arsenal', 'playing_time_min' => '1792']);
        $leverkusen = SourceFixtures::top5Row(['player' => 'A', 'team' => 'Leverkusen', 'league' => 'GER-Bundesliga', 'playing_time_min' => '']);
        $other = SourceFixtures::top5Row(['player' => 'B']);
        $this->writeCsv(array_keys($arsenal), [$arsenal, $leverkusen, $other]);

        $result = (new Top5CsvReader($this->directory))->read('2526');

        self::assertSame(['B|1995|Club|ENG-Premier League'], array_keys($result['rows']));
        self::assertCount(2, $result['rejected']);
    }

    public function testFailsWhenTheDatasetLostAColumn(): void
    {
        $row = SourceFixtures::top5Row();
        unset($row['us_xg']);
        $this->writeCsv(array_keys($row), [$row]);

        $this->expectExceptionMessage('us_xg');
        (new Top5CsvReader($this->directory))->read('2526');
    }

    public function testFailsWithAHelpfulMessageWhenNotCloned(): void
    {
        $this->expectExceptionMessage('Clone the dataset first');
        (new Top5CsvReader($this->directory . '/nope'))->read('2526');
    }

    /**
     * Rows keyed by column are written in header order; a plain list is written as is.
     *
     * @param list<string>                             $header
     * @param list<array<string, string>|list<string>> $rows
     */
    private function writeCsv(array $header, array $rows): void
    {
        $fh = fopen($this->directory . '/data/master/player_seasons.csv', 'w');
        self::assertNotFalse($fh);
        fputcsv($fh, $header, escape: '');
        foreach ($rows as $row) {
            fputcsv($fh, array_is_list($row) ? $row : array_map(static fn (string $column) => $row[$column], $header), escape: '');
        }
        fclose($fh);
    }
}
