<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'unaccent_lower(): accent- and case-insensitive name search ("mbappe" finds "Mbappé")';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE EXTENSION IF NOT EXISTS unaccent');
        // unaccent() is STABLE; wrapping it with a fixed dictionary makes it safe to mark IMMUTABLE.
        $this->addSql("CREATE OR REPLACE FUNCTION unaccent_lower(text) RETURNS text
            LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT
            AS \$\$ SELECT lower(public.unaccent('public.unaccent'::regdictionary, \$1)) \$\$");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP FUNCTION IF EXISTS unaccent_lower(text)');
    }
}
