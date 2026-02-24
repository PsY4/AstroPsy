<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251023053943 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE session_author (session_id INT NOT NULL, author_id INT NOT NULL, PRIMARY KEY(session_id, author_id))');
        $this->addSql('CREATE INDEX IDX_D2C4D291613FECDF ON session_author (session_id)');
        $this->addSql('CREATE INDEX IDX_D2C4D291F675F31B ON session_author (author_id)');
        $this->addSql('ALTER TABLE session_author ADD CONSTRAINT FK_D2C4D291613FECDF FOREIGN KEY (session_id) REFERENCES session (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE session_author ADD CONSTRAINT FK_D2C4D291F675F31B FOREIGN KEY (author_id) REFERENCES author (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE session_author DROP CONSTRAINT FK_D2C4D291613FECDF');
        $this->addSql('ALTER TABLE session_author DROP CONSTRAINT FK_D2C4D291F675F31B');
        $this->addSql('DROP TABLE session_author');
    }
}
