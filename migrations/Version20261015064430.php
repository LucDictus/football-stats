<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261015064430 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Finer role per player season (EA SPORTS FC position or inferred) and its source';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE player_season ADD role VARCHAR(2) DEFAULT NULL');
        $this->addSql('ALTER TABLE player_season ADD role_source VARCHAR(12) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE player_season DROP role');
        $this->addSql('ALTER TABLE player_season DROP role_source');
    }
}
