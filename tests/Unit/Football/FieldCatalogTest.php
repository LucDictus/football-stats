<?php

declare(strict_types=1);

namespace App\Tests\Unit\Football;

use App\DataImport\Sofascore\SofascoreClient;
use App\Football\Data\FieldCatalog;
use App\Football\Data\Origin;
use PHPUnit\Framework\TestCase;

final class FieldCatalogTest extends TestCase
{
    public function testEveryClColumnIsFetchedFromSofascore(): void
    {
        foreach (FieldCatalog::all() as $field) {
            if ($field->clColumn !== null) {
                self::assertContains($field->clColumn, SofascoreClient::FIELDS, "{$field->key} is not requested from SofaScore");
            }
        }
    }

    public function testLeagueColumnsMatchTheirProviderPrefix(): void
    {
        $prefixes = [Origin::Fbref->value => ['fb_', 'playing_time_'], Origin::Understat->value => ['us_'], Origin::Sofascore->value => ['ss_']];

        foreach (FieldCatalog::all() as $field) {
            if ($field->origin === Origin::Derived) {
                self::assertNull($field->leagueColumn, "{$field->key} is derived and has no column");
                continue;
            }
            $matches = array_filter($prefixes[$field->origin->value], static fn ($p) => str_starts_with((string) $field->leagueColumn, $p));
            self::assertNotEmpty($matches, "{$field->key}: column {$field->leagueColumn} does not belong to {$field->origin->value}");
        }
    }
}
