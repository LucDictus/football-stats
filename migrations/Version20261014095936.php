<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261014095936 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Player score composite (component mean before normalising within the role)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE player_score ADD composite DOUBLE PRECISION DEFAULT NULL');
        // Runs made so far are of models without roles, where the composite is the score.
        $this->addSql('UPDATE player_score SET composite = score');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE player_score DROP composite');
    }
}
