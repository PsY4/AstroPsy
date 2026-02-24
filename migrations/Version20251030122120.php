<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251030122120 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE setup_part_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE setup_part (id INT NOT NULL, make VARCHAR(255) DEFAULT NULL, model VARCHAR(255) DEFAULT NULL, type VARCHAR(255) DEFAULT NULL, notes TEXT DEFAULT NULL, url VARCHAR(1024) DEFAULT NULL, images TEXT DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('COMMENT ON COLUMN setup_part.images IS \'(DC2Type:array)\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP SEQUENCE setup_part_id_seq CASCADE');
        $this->addSql('DROP TABLE setup_part');
    }
}
