<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260221225219 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE session ADD setup_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE session ADD CONSTRAINT FK_D044D5D4CDCDB68E FOREIGN KEY (setup_id) REFERENCES setup (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_D044D5D4CDCDB68E ON session (setup_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE session DROP CONSTRAINT FK_D044D5D4CDCDB68E');
        $this->addSql('DROP INDEX IDX_D044D5D4CDCDB68E');
        $this->addSql('ALTER TABLE session DROP setup_id');
    }
}
