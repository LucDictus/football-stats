<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Links football-data.org players (players table, top scorers incl. CL/WC) to
 * Top-5 dataset players (ext_player_seasons), so both sources end up in one row.
 *
 * Matching, in order (only unique matches are accepted, ambiguous ones are skipped):
 *   1. full name + birth year
 *   2. surname + first initial + birth year
 *   3. surname + birth year
 *
 * Unmatched players simply have no link; their CL/WC numbers stay NULL in the
 * player table instead of being guessed.
 */
final class PlayerLinker
{
    /** @return array{linked: int, unmatched: list<string>}|null null when a source isn't imported yet */
    public static function run(PDO $db): ?array
    {
        foreach (['ext_player_seasons', 'players'] as $table) {
            $stmt = $db->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
            $stmt->execute([$table]);
            if (!$stmt->fetchColumn()) {
                return null;
            }
        }

        $db->exec('DROP TABLE IF EXISTS player_links');
        $db->exec(
            'CREATE TABLE player_links (
                ext_id INTEGER NOT NULL,
                fd_player_id INTEGER NOT NULL PRIMARY KEY,
                method TEXT NOT NULL
            )'
        );

        // Index the dataset players per matching key; a key with >1 player is ambiguous.
        $index = ['full' => [], 'initial' => [], 'surname' => []];
        foreach ($db->query('SELECT id, player, born FROM ext_player_seasons') as $p) {
            foreach (self::keys($p['player'], $p['born']) as $type => $key) {
                $index[$type][$key][] = (int) $p['id'];
            }
        }

        $insert = $db->prepare('INSERT INTO player_links (ext_id, fd_player_id, method) VALUES (?, ?, ?)');
        $methods = ['full' => 'name + birth year', 'initial' => 'initial + surname + birth year', 'surname' => 'surname + birth year'];
        $linked = 0;
        $unmatched = [];

        $db->beginTransaction();
        foreach ($db->query('SELECT id, name, date_of_birth FROM players') as $fd) {
            $born = $fd['date_of_birth'] ? (int) substr($fd['date_of_birth'], 0, 4) : null;
            $match = null;
            foreach (self::keys($fd['name'], $born) as $type => $key) {
                $candidates = array_unique($index[$type][$key] ?? []);
                if (count($candidates) === 1) {
                    $match = [$candidates[0], $methods[$type]];
                    break;
                }
            }
            if ($match === null) {
                $unmatched[] = $fd['name'];
                continue;
            }
            $insert->execute([$match[0], $fd['id'], $match[1]]);
            $linked++;
        }
        $db->commit();

        return ['linked' => $linked, 'unmatched' => $unmatched];
    }

    /** @return array<string, string> matching keys; empty without a birth year */
    private static function keys(string $name, mixed $born): array
    {
        if (empty($born)) {
            return [];
        }
        $parts = explode(' ', self::normalize($name));
        $surname = end($parts);
        $keys = ['full' => implode(' ', $parts) . '|' . $born];
        if (count($parts) > 1) {
            $keys['initial'] = $parts[0][0] . ' ' . $surname . '|' . $born;
        }
        $keys['surname'] = $surname . '|' . $born;

        return $keys;
    }

    public static function normalize(string $name): string
    {
        $previous = setlocale(LC_CTYPE, '0');
        setlocale(LC_CTYPE, 'C.UTF-8');
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $name);
        setlocale(LC_CTYPE, $previous ?: 'C');

        $ascii = strtolower($ascii !== false ? $ascii : $name);
        $ascii = preg_replace('/[^a-z0-9 ]+/', '', str_replace('-', ' ', $ascii));

        return trim((string) preg_replace('/\s+/', ' ', $ascii));
    }
}
