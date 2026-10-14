<?php

declare(strict_types=1);

namespace App\Tests\Unit\DataImport;

use App\DataImport\Sofascore\ClRecordSelector;
use App\Tests\Support\SourceFixtures as F;
use PHPUnit\Framework\TestCase;

final class ClRecordSelectorTest extends TestCase
{
    public function testKeepsTheClubWithMostMinutesAndMarksItPartial(): void
    {
        $result = (new ClRecordSelector())->select([
            '7|1' => F::sofascoreRecord(7, 'A', 1, 'First', ['minutesPlayed' => 200]),
            '7|2' => F::sofascoreRecord(7, 'A', 2, 'Second', ['minutesPlayed' => 500]),
            '8|1' => F::sofascoreRecord(8, 'B', 1, 'First'),
        ]);

        self::assertSame(['7|2', '8|1'], array_keys($result['records']));
        self::assertSame(['7|2' => 'one of 2 CL clubs (500 of 700 minutes)'], $result['partial']);
        self::assertSame([], $result['rejected']);
    }

    public function testRejectsRecordsWithoutMinutes(): void
    {
        $result = (new ClRecordSelector())->select(['9|1' => F::sofascoreRecord(9, 'C', 1, 'Club', ['minutesPlayed' => null])]);

        self::assertSame([], $result['records']);
        self::assertSame(['CL 9|1: C has no minutes'], $result['rejected']);
    }
}
