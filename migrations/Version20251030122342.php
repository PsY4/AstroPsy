<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251030122342 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE setup_part ADD setup_id INT NOT NULL');
        $this->addSql('ALTER TABLE setup_part ADD CONSTRAINT FK_A76008E1CDCDB68E FOREIGN KEY (setup_id) REFERENCES setup (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_A76008E1CDCDB68E ON setup_part (setup_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE setup_part DROP CONSTRAINT FK_A76008E1CDCDB68E');
        $this->addSql('DROP INDEX IDX_A76008E1CDCDB68E');
        $this->addSql('ALTER TABLE setup_part DROP setup_id');
    }
}
