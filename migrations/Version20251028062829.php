<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251028062829 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE observatory_author (observatory_id INT NOT NULL, author_id INT NOT NULL, PRIMARY KEY(observatory_id, author_id))');
        $this->addSql('CREATE INDEX IDX_2E2B93B897EE0280 ON observatory_author (observatory_id)');
        $this->addSql('CREATE INDEX IDX_2E2B93B8F675F31B ON observatory_author (author_id)');
        $this->addSql('ALTER TABLE observatory_author ADD CONSTRAINT FK_2E2B93B897EE0280 FOREIGN KEY (observatory_id) REFERENCES observatory (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE observatory_author ADD CONSTRAINT FK_2E2B93B8F675F31B FOREIGN KEY (author_id) REFERENCES author (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE observatory ADD favorite BOOLEAN DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE observatory_author DROP CONSTRAINT FK_2E2B93B897EE0280');
        $this->addSql('ALTER TABLE observatory_author DROP CONSTRAINT FK_2E2B93B8F675F31B');
        $this->addSql('DROP TABLE observatory_author');
        $this->addSql('ALTER TABLE observatory DROP favorite');
    }
}
