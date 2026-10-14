<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261014111301 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Player score: positional score and contribution in goals (impact) for blended models';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE player_score ADD positional DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE player_score ADD impact JSONB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE player_score DROP positional');
        $this->addSql('ALTER TABLE player_score DROP impact');
    }
}
