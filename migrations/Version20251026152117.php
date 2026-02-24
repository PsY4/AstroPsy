<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251026152117 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE doc_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE doc (id INT NOT NULL, target_id INT DEFAULT NULL, session_id INT DEFAULT NULL, title VARCHAR(255) DEFAULT NULL, doc TEXT DEFAULT NULL, creation_date TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, update_date TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_8641FD64158E0B66 ON doc (target_id)');
        $this->addSql('CREATE INDEX IDX_8641FD64613FECDF ON doc (session_id)');
        $this->addSql('ALTER TABLE doc ADD CONSTRAINT FK_8641FD64158E0B66 FOREIGN KEY (target_id) REFERENCES target (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE doc ADD CONSTRAINT FK_8641FD64613FECDF FOREIGN KEY (session_id) REFERENCES session (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP SEQUENCE doc_id_seq CASCADE');
        $this->addSql('ALTER TABLE doc DROP CONSTRAINT FK_8641FD64158E0B66');
        $this->addSql('ALTER TABLE doc DROP CONSTRAINT FK_8641FD64613FECDF');
        $this->addSql('DROP TABLE doc');
    }
}
