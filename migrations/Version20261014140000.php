<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Score runs: store the canonical definition hash (sorted keys), which can be recomputed from the stored jsonb definition';
    }

    public function up(Schema $schema): void
    {
        foreach ($this->connection->fetchAllAssociative('SELECT id, definition FROM score_run') as $run) {
            $hash = hash('sha256', self::canonical(json_decode($run['definition'], true, flags: JSON_THROW_ON_ERROR)));
            $this->addSql('UPDATE score_run SET definition_hash = ? WHERE id = ?', [$hash, $run['id']]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The previous hashes were computed over unordered keys.');
    }

    /** Same as App\Football\Scoring\CanonicalJson::encode, inlined so the migration never changes. */
    private static function canonical(mixed $value): string
    {
        $sort = static function (mixed $v) use (&$sort): mixed {
            if (!is_array($v)) {
                return $v;
            }
            $v = array_map($sort, $v);
            if (!array_is_list($v)) {
                ksort($v);
            }

            return $v;
        };

        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
