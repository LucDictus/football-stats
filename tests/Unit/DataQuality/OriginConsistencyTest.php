<?php

declare(strict_types=1);

namespace App\Tests\Unit\DataQuality;

use App\DataQuality\OriginConsistency;
use App\DataQuality\OriginStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OriginConsistencyTest extends TestCase
{
    /** @param list<float> $stints */
    #[DataProvider('cases')]
    public function testStatus(?float $origin, float $fbref, array $stints, OriginStatus $expected): void
    {
        self::assertSame($expected, OriginConsistency::check($origin, $fbref, $stints)->status);
    }

    /** @return iterable<string, array{?float, float, list<float>, OriginStatus}> */
    public static function cases(): iterable
    {
        yield 'no record is missing, not zero' => [null, 2000.0, [2000.0], OriginStatus::Missing];
        yield 'small stoppage-time difference' => [1900.0, 1895.0, [1812.0, 83.0], OriginStatus::Ok];
        yield 'within 15%' => [1700.0, 1950.0, [1950.0], OriginStatus::Ok];
        yield 'small samples use the 30-minute floor' => [45.0, 20.0, [20.0], OriginStatus::Ok];
        yield 'transfer: provider has one club only (Hincapié)' => [90.0, 1882.0, [1792.0, 90.0], OriginStatus::Partial];
        yield 'single club, far fewer minutes (Wesley)' => [91.0, 2448.0, [2448.0], OriginStatus::Suspect];
        yield 'single club, far more minutes' => [833.0, 293.0, [293.0], OriginStatus::Suspect];
        yield 'after a transfer, more minutes than FBref' => [3000.0, 2000.0, [1000.0, 1000.0], OriginStatus::Suspect];
    }

    public function testGoalsDisagreementMakesSuspect(): void
    {
        $check = OriginConsistency::check(1460.0, 1569.0, [1569.0], originGoals: 5.0, fbrefGoals: 1.0);

        self::assertSame(OriginStatus::Suspect, $check->status);
        self::assertSame('5 goals vs 1 in FBref', $check->reason);
    }

    public function testOneGoalDifferenceIsAttributionNoise(): void
    {
        self::assertSame(OriginStatus::Ok, OriginConsistency::check(2000.0, 2000.0, [2000.0], 6.0, 5.0)->status);
    }

    public function testPartialSaysWhenItMatchesOneStint(): void
    {
        self::assertSame('covers 90 of 1882 minutes (one club stint)', OriginConsistency::check(90.0, 1882.0, [1792.0, 90.0])->reason);
    }
}
