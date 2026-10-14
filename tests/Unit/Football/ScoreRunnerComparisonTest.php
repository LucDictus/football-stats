<?php

declare(strict_types=1);

namespace App\Tests\Unit\Football;

use App\Football\Scoring\ScoreRunner;
use PHPUnit\Framework\TestCase;

final class ScoreRunnerComparisonTest extends TestCase
{
    public function testFloatsThatWentThroughTheDatabaseStillCompareEqual(): void
    {
        // A real case: rounding both to 9 decimals gave .081 and .082.
        self::assertTrue(ScoreRunner::same([350, 68.70927908149999, ['v' => 2953]], [350, 68.7092790815, ['v' => 2953.0]]));
    }

    public function testRealDifferencesAreNotEqual(): void
    {
        self::assertFalse(ScoreRunner::same([1, 70.0], [1, 70.1]));
        self::assertFalse(ScoreRunner::same([1, 70.0], [2, 70.0]));
        self::assertFalse(ScoreRunner::same([null], [0.0]), 'unknown is not zero');
        self::assertFalse(ScoreRunner::same(['a' => 1], ['b' => 1]));
        self::assertFalse(ScoreRunner::same(['a' => 1], ['a' => 1, 'b' => 2]));
    }
}
