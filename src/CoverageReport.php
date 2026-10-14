<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Builds the field coverage report from config/fields.php and the imported data.
 *
 * For every field and every source it reports which competitions are covered,
 * how many records are in scope, and how many of those are missing (NULL).
 * "Missing" is never counted as zero; a field without a source is reported as such.
 */
final class CoverageReport
{
    public function __construct(
        private readonly PDO $db,
        private readonly array $registry,
        private readonly int $minMinutes = 900,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function build(): array
    {
        $hasTop5 = $this->tableExists(Top5Importer::TABLE);
        $hasMatches = $this->tableExists('matches');
        $rows = [];

        foreach ($this->registry['fields'] as $key => $field) {
            foreach ($field['sources'] as $source) {
                $row = [
                    'field' => $key,
                    'label' => $field['label'],
                    'category' => $field['category'],
                    'priority' => $field['priority'],
                    'applies_to' => $field['applies_to'],
                    'source' => $source['source'] === null
                        ? 'none'
                        : ($this->registry['sources'][$source['source']]['label'] ?? $source['source']),
                    'source_column' => $source['column'] ?? '',
                    'level' => $source['level'] ?? '',
                    'competitions_planned' => implode(',', $source['competitions']),
                    'competitions_with_data' => '',
                    'records_in_scope' => null,
                    'records_missing' => null,
                    'missing_pct' => null,
                    'missing_min_minutes' => null,
                    'status' => '',
                    'note' => $source['note'] ?? '',
                ];

                $row = match (true) {
                    $source['source'] === null => [...$row, 'status' => 'no source'],
                    $source['source'] === Top5Importer::SOURCE => $hasTop5
                        ? $this->top5Coverage($row, $key, $field['applies_to'], $source['competitions'])
                        : [...$row, 'status' => 'not imported'],
                    $source['level'] === 'match' => $hasMatches
                        ? $this->matchCoverage($row, $key, $source['competitions'])
                        : [...$row, 'status' => 'not imported'],
                    $source['source'] === 'football-data' => $this->scorersCoverage($row, $source['competitions']),
                    default => [...$row, 'status' => 'unknown source'],
                };

                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** Stores the report in field_coverage (replaced on every run). */
    public function save(array $rows): void
    {
        $this->db->exec('DROP TABLE IF EXISTS field_coverage');
        $columns = array_keys($rows[0]);
        $this->db->exec('CREATE TABLE field_coverage (' . implode(', ', $columns) . ', generated_at TEXT)');

        $insert = $this->db->prepare(sprintf(
            'INSERT INTO field_coverage (%s, generated_at) VALUES (%s, ?)',
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?'))
        ));
        $now = gmdate('c');
        foreach ($rows as $row) {
            $insert->execute([...array_values($row), $now]);
        }
    }

    private function top5Coverage(array $row, string $field, string $appliesTo, array $planned): array
    {
        $table = Top5Importer::TABLE;
        $scope = $appliesTo === 'GK' ? 'is_goalkeeper = 1' : '1 = 1';

        $stats = $this->db->query(
            "SELECT COUNT(*) AS total,
                    SUM({$field} IS NULL) AS missing,
                    SUM(minutes >= {$this->minMinutes}) AS total_min,
                    SUM(minutes >= {$this->minMinutes} AND {$field} IS NULL) AS missing_min
             FROM {$table} WHERE {$scope}"
        )->fetch();

        // Competitions where at least one player has a value for this field.
        $withData = [];
        foreach ($planned as $code) {
            $count = $this->db->query(
                "SELECT COUNT(*) FROM {$table}
                 WHERE {$scope} AND {$field} IS NOT NULL AND (',' || leagues || ',') LIKE '%,{$code},%'"
            )->fetchColumn();
            if ($count > 0) {
                $withData[] = $code;
            }
        }

        $total = (int) $stats['total'];
        $missing = (int) $stats['missing'];

        return [
            ...$row,
            'competitions_with_data' => implode(',', $withData),
            'records_in_scope' => $total,
            'records_missing' => $missing,
            'missing_pct' => $total > 0 ? round($missing / $total * 100, 1) : null,
            'missing_min_minutes' => sprintf('%d of %d', (int) $stats['missing_min'], (int) $stats['total_min']),
            'status' => $missing === 0 ? 'complete' : 'partial',
        ];
    }

    private function matchCoverage(array $row, string $field, array $planned): array
    {
        $codes = implode(',', array_map(fn ($c) => $this->db->quote($c), $planned));
        $condition = match ($field) {
            'opponent_strength' => 'home_strength IS NULL OR away_strength IS NULL',
            'result' => "status = 'FINISHED' AND (home_score IS NULL OR away_score IS NULL)",
            default => '0',
        };

        $stats = $this->db->query(
            "SELECT COUNT(*) AS total, SUM({$condition}) AS missing,
                    GROUP_CONCAT(DISTINCT competition_code) AS codes
             FROM match_records WHERE competition_code IN ({$codes})"
        )->fetch();

        $total = (int) $stats['total'];
        $missing = (int) $stats['missing'];

        if ($total === 0) {
            return [...$row, 'status' => 'not imported'];
        }

        return [
            ...$row,
            'competitions_with_data' => (string) $stats['codes'],
            'records_in_scope' => $total,
            'records_missing' => $missing,
            'missing_pct' => $total > 0 ? round($missing / $total * 100, 1) : null,
            'missing_min_minutes' => 'n/a (match records)',
            'status' => $missing === 0 ? 'complete' : 'partial',
        ];
    }

    /** football-data.org /scorers: only the top N players, so the real population is unknown. */
    private function scorersCoverage(array $row, array $planned): array
    {
        if (!$this->tableExists('player_competition_stats')) {
            return [...$row, 'status' => 'not imported'];
        }

        $codes = implode(',', array_map(fn ($c) => $this->db->quote($c), $planned));
        $count = (int) $this->db->query(
            "SELECT COUNT(*) FROM player_competition_stats s
             JOIN competitions c ON c.id = s.competition_id
             WHERE c.code IN ({$codes})"
        )->fetchColumn();

        return [
            ...$row,
            'competitions_with_data' => $count > 0 ? implode(',', $planned) : '',
            'records_in_scope' => $count,
            'records_missing' => null,
            'missing_min_minutes' => 'unknown',
            'status' => $count > 0 ? 'partial (top scorers only)' : 'not imported',
        ];
    }

    private function tableExists(string $name): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM sqlite_master WHERE name = ? AND type IN ('table', 'view')");
        $stmt->execute([$name]);

        return (bool) $stmt->fetchColumn();
    }
}
