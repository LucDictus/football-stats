<?php

declare(strict_types=1);

namespace App\Tests\Unit\Football;

use App\Football\Data\RoleCatalog;
use PHPUnit\Framework\TestCase;

final class RoleCatalogTest extends TestCase
{
    public function testTheEaFcRoleMustSuitTheSourcesPositionGroup(): void
    {
        self::assertSame('W', RoleCatalog::forGroup('W', 'MF'));
        self::assertSame('FB', RoleCatalog::forGroup('W', 'DF'), 'a winger listed as a defender is a wing-back');
        self::assertNull(RoleCatalog::forGroup('CM', 'DF'), 'not a role a defender can play: inferred instead');
        self::assertNull(RoleCatalog::forGroup('CB', 'FW'));
        self::assertSame('CM', RoleCatalog::forGroup('CM', null), 'no group to check against');
    }

    public function testClubPositionFirstAndWingBacksInABackThree(): void
    {
        self::assertSame(['W', RoleCatalog::SOURCE_CLUB_POSITION], RoleCatalog::fromEaFc('RW', 'CAM'));
        self::assertSame(['AM', RoleCatalog::SOURCE_BEST_POSITION], RoleCatalog::fromEaFc('RES', 'CAM'));
        self::assertSame(['FB', RoleCatalog::SOURCE_CLUB_POSITION], RoleCatalog::fromEaFc('RM', 'RWB'));
        self::assertNull(RoleCatalog::fromEaFc('SUB', null));
    }
}
