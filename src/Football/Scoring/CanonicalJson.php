<?php

declare(strict_types=1);

namespace App\Football\Scoring;

/**
 * JSON with object keys sorted at every level. PostgreSQL's jsonb re-orders keys, so a
 * hash over canonical JSON is the only one that can be checked again from stored data.
 */
final class CanonicalJson
{
    public static function encode(mixed $value): string
    {
        return json_encode(self::sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    public static function hash(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    private static function sort(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $sorted = array_map(self::sort(...), $value);
        if (!array_is_list($sorted)) {
            ksort($sorted);
        }

        return $sorted;
    }
}
