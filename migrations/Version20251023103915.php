<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251023103915 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE session ADD observatory_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE session ADD CONSTRAINT FK_D044D5D497EE0280 FOREIGN KEY (observatory_id) REFERENCES observatory (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_D044D5D497EE0280 ON session (observatory_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE session DROP CONSTRAINT FK_D044D5D497EE0280');
        $this->addSql('DROP INDEX IDX_D044D5D497EE0280');
        $this->addSql('ALTER TABLE session DROP observatory_id');
    }
}
