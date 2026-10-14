<?php

declare(strict_types=1);

namespace App\Tests\Unit\Football;

use App\Football\Data\DerivedRates;
use PHPUnit\Framework\TestCase;

final class DerivedRatesTest extends TestCase
{
    public function testRatesFromCounts(): void
    {
        $rates = DerivedRates::compute(['aerial_duels_won' => 60.0, 'aerial_duels_lost' => 20.0, 'tackles_won' => 30.0, 'tackles' => 40.0], goalkeeper: false);

        self::assertSame(75.0, $rates['aerial_win_pct']);
        self::assertSame(75.0, $rates['tackle_success_pct']);
        self::assertNull($rates['save_pct'], 'goalkeepers only');
    }

    public function testTooFewAttemptsIsUnknownNotARate(): void
    {
        $rates = DerivedRates::compute(['aerial_duels_won' => 2.0, 'aerial_duels_lost' => 1.0, 'tackles_won' => 3.0, 'tackles' => 4.0], goalkeeper: false);

        self::assertNull($rates['aerial_win_pct']);
        self::assertNull($rates['tackle_success_pct']);
    }

    public function testMissingCountsGiveNoRate(): void
    {
        $rates = DerivedRates::compute(['saves' => 80.0], goalkeeper: true);

        self::assertNull($rates['save_pct']);
    }

    public function testSavePercentage(): void
    {
        self::assertSame(75.0, DerivedRates::compute(['saves' => 90.0, 'goals_conceded' => 30.0], goalkeeper: true)['save_pct']);
    }
}
