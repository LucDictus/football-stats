<?php

declare(strict_types=1);

namespace App\Tests\Unit\Football;

use App\Football\Scoring\Percentile;
use PHPUnit\Framework\TestCase;

final class PercentileTest extends TestCase
{
    public function testMidRankPercentiles(): void
    {
        self::assertSame(['a' => 12.5, 'b' => 37.5, 'c' => 62.5, 'd' => 87.5], Percentile::ranks(['a' => 1.0, 'b' => 2.0, 'c' => 3.0, 'd' => 4.0]));
    }

    public function testTiesShareAPercentile(): void
    {
        self::assertSame([25.0, 25.0, 75.0, 75.0], Percentile::ranks([0.0, 0.0, 5.0, 5.0]));
    }

    public function testMissingValuesStayMissingAndAreNotCounted(): void
    {
        self::assertSame([25.0, null, 75.0], Percentile::ranks([1.0, null, 2.0]));
    }

    public function testLoneValueScoresFifty(): void
    {
        self::assertSame([50.0], Percentile::ranks([7.0]));
    }
}
