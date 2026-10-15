<?php

declare(strict_types=1);

namespace App\Football\Standards;

/**
 * The derived standards, frozen as JSON in config/standards/: a model built on them reads
 * this file, so its definition (and hash) is fixed until the standards are derived again
 * under a new id.
 *
 * @phpstan-import-type RoleStandard from StandardsDeriver
 *
 * @phpstan-type Standards array{id: string, seasons: list<string>, derivation: array<string, mixed>, roles: array<string, RoleStandard>, validation: array<string, array<string, mixed>>}
 */
final class StandardsFile
{
    /**
     * @param list<string>                        $seasons
     * @param array<string, RoleStandard>         $roles
     * @param array<string, array<string, mixed>> $validation
     */
    public static function encode(string $id, array $seasons, array $roles, array $validation): string
    {
        $data = [
            'id' => $id,
            'seasons' => $seasons,
            'derivation' => [
                'min_minutes' => StandardsDeriver::MIN_MINUTES,
                'min_coverage' => StandardsDeriver::MIN_COVERAGE,
                'z_one_sided' => StandardsDeriver::Z_ONE_SIDED,
                'same_thing' => StandardsDeriver::SAME_THING,
                'min_pairs' => StandardsDeriver::MIN_PAIRS,
            ],
            'roles' => self::round($roles),
            'validation' => self::round($validation),
        ];

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * Writes the standards unless the file exists with different numbers and $force is false:
     * a model built on them would silently change. Returns false when refused (nothing written).
     */
    public static function write(string $file, string $json, bool $force = false): bool
    {
        if (is_file($file) && file_get_contents($file) !== $json && !$force) {
            return false;
        }
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0o775, true);
        }
        file_put_contents($file, $json);

        return true;
    }

    /** @return Standards */
    public static function read(string $file): array
    {
        $json = @file_get_contents($file);
        if ($json === false) {
            throw new \RuntimeException("Standards file {$file} not found: run app:standards:derive");
        }

        /** @var Standards */
        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    /** Six decimals: the file stays readable and the same on every machine. */
    private static function round(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::round(...), $value);
        }

        return is_float($value) ? round($value, 6) : $value;
    }
}
