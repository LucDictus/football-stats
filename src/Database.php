<?php

declare(strict_types=1);

namespace App;

use PDO;

final class Database
{
    public static function connect(string $path): PDO
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $pdo = new PDO('sqlite:' . $path, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = DELETE');

        return $pdo;
    }

    public static function migrate(PDO $pdo, string $schemaFile): void
    {
        $pdo->exec((string) file_get_contents($schemaFile));
    }

    /**
     * INSERT ... ON CONFLICT(keys) DO UPDATE for all non-key columns.
     *
     * @param array<string, mixed> $row
     * @param list<string>         $keys
     */
    public static function upsert(PDO $pdo, string $table, array $row, array $keys = ['id']): void
    {
        $columns = array_keys($row);
        $updates = array_diff($columns, $keys);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT (%s) DO %s',
            $table,
            implode(', ', $columns),
            implode(', ', array_map(static fn ($c) => ':' . $c, $columns)),
            implode(', ', $keys),
            $updates === []
                ? 'NOTHING'
                : 'UPDATE SET ' . implode(', ', array_map(static fn ($c) => "{$c} = excluded.{$c}", $updates))
        );

        $pdo->prepare($sql)->execute($row);
    }
}
