<?php

declare(strict_types=1);

namespace App\Tests\Unit\Football;

use App\Football\Scoring\Normalization;
use PHPUnit\Framework\TestCase;

final class NormalizationTest extends TestCase
{
    public function testStandardScoresAreFiftyPlusTenPerStandardDeviation(): void
    {
        // mean 5, standard deviation 2
        self::assertSame([40.0, 40.0, 40.0, 40.0, 60.0, 60.0, 60.0, 60.0], Normalization::Standard->scores([3.0, 3.0, 3.0, 3.0, 7.0, 7.0, 7.0, 7.0]));
    }

    public function testStandardScoresKeepARunawayLeaderApart(): void
    {
        $scores = Normalization::Standard->scores([1.0, 2.0, 2.0, 3.0, 3.0, 20.0]);
        $percentiles = Normalization::Percentile->scores([1.0, 2.0, 2.0, 3.0, 3.0, 20.0]);

        // A percentile only knows he is first; a standard score also knows by how much.
        self::assertGreaterThan($percentiles[5] - $percentiles[4], $scores[5] - $scores[4]);
    }

    public function testStandardScoresAreClampedToZeroToHundred(): void
    {
        $scores = Normalization::Standard->scores([...array_fill(0, 99, 0.0), 100.0]);

        self::assertSame(100.0, $scores[99]);
        foreach ($scores as $score) {
            self::assertGreaterThanOrEqual(0.0, $score);
        }
    }

    public function testLowerIsBetterIsMirrored(): void
    {
        $scores = Normalization::Standard->scores([0.0, 1.0], lowerIsBetter: true);

        self::assertGreaterThan($scores[1], $scores[0]);
        self::assertSame([75.0, 25.0], Normalization::Percentile->scores([0.0, 1.0], lowerIsBetter: true));
    }

    public function testNullsStayNullAndIdenticalValuesScoreFifty(): void
    {
        self::assertSame([50.0, null, 50.0], Normalization::Standard->scores([4.0, null, 4.0]));
    }
}
