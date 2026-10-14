<?php

declare(strict_types=1);

namespace App\Tests\Unit\Football;

use App\Football\Scoring\CanonicalJson;
use App\Football\Scoring\ModelCatalog;
use PHPUnit\Framework\TestCase;

final class CanonicalJsonTest extends TestCase
{
    public function testKeyOrderDoesNotChangeTheHash(): void
    {
        self::assertSame(
            CanonicalJson::hash(['b' => 1, 'a' => ['y' => 2.0, 'x' => [3, 1]]]),
            CanonicalJson::hash(['a' => ['x' => [3, 1], 'y' => 2.0], 'b' => 1]),
        );
    }

    public function testListOrderDoesChangeTheHash(): void
    {
        self::assertNotSame(CanonicalJson::hash([1, 2]), CanonicalJson::hash([2, 1]));
    }

    public function testAStoredDefinitionVerifiesAgainstTheModelHash(): void
    {
        $model = ModelCatalog::get(ModelCatalog::DEFAULT);
        // What jsonb gives back: same data, keys in another order.
        $stored = json_decode(CanonicalJson::encode(array_reverse($model->toArray(), true)), true);

        self::assertSame($model->hash(), CanonicalJson::hash($stored));
    }
}
