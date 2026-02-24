<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251028121800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE setup_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE setup (id INT NOT NULL, author_id INT DEFAULT NULL, observatory_id INT DEFAULT NULL, name VARCHAR(255) DEFAULT NULL, notes TEXT DEFAULT NULL, logo VARCHAR(512) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_251D5630F675F31B ON setup (author_id)');
        $this->addSql('CREATE INDEX IDX_251D563097EE0280 ON setup (observatory_id)');
        $this->addSql('ALTER TABLE setup ADD CONSTRAINT FK_251D5630F675F31B FOREIGN KEY (author_id) REFERENCES author (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE setup ADD CONSTRAINT FK_251D563097EE0280 FOREIGN KEY (observatory_id) REFERENCES observatory (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP SEQUENCE setup_id_seq CASCADE');
        $this->addSql('ALTER TABLE setup DROP CONSTRAINT FK_251D5630F675F31B');
        $this->addSql('ALTER TABLE setup DROP CONSTRAINT FK_251D563097EE0280');
        $this->addSql('DROP TABLE setup');
    }
}
