<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251010060653 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE phd2_guiding_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE phd2_guiding (id INT NOT NULL, session_id INT DEFAULT NULL, source_path VARCHAR(512) NOT NULL, section_index INT DEFAULT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, headers JSON DEFAULT NULL, ra_errors JSON DEFAULT NULL, dec_errors JSON DEFAULT NULL, ra_corrections JSON DEFAULT NULL, dec_corrections JSON DEFAULT NULL, total_errors JSON DEFAULT NULL, rms_ra DOUBLE PRECISION DEFAULT NULL, rms_dec DOUBLE PRECISION DEFAULT NULL, rms_total DOUBLE PRECISION DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_BB7344DE613FECDF ON phd2_guiding (session_id)');
        $this->addSql('COMMENT ON COLUMN phd2_guiding.started_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE phd2_guiding ADD CONSTRAINT FK_BB7344DE613FECDF FOREIGN KEY (session_id) REFERENCES session (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP SEQUENCE phd2_guiding_id_seq CASCADE');
        $this->addSql('ALTER TABLE phd2_guiding DROP CONSTRAINT FK_BB7344DE613FECDF');
        $this->addSql('DROP TABLE phd2_guiding');
    }
}
