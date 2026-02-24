<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251031062557 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE author ALTER COLUMN astrobin_stats DROP DEFAULT');
        $this->addSql('ALTER TABLE author ALTER COLUMN astrobin_stats TYPE JSON USING astrobin_stats::json');
        $this->addSql("ALTER TABLE author ALTER COLUMN astrobin_stats SET DEFAULT '{}'::json");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE author ALTER astrobin_stats TYPE VARCHAR(512)');
    }
}
