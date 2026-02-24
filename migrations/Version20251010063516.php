<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251010063516 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE phd2_guiding DROP CONSTRAINT FK_BB7344DE613FECDF');
        $this->addSql('ALTER TABLE phd2_guiding ADD source_sha1 VARCHAR(40) DEFAULT NULL');
        $this->addSql('ALTER TABLE phd2_guiding ADD ended_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE phd2_guiding ADD mount VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE phd2_guiding ADD pixel_scale_arcsec_per_px DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE phd2_guiding ADD exposure_ms INT DEFAULT NULL');
        $this->addSql('ALTER TABLE phd2_guiding ADD lock_position JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE phd2_guiding ADD hfd_px DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE phd2_guiding ADD frame_count INT DEFAULT NULL');
        $this->addSql('ALTER TABLE phd2_guiding ADD drop_count INT DEFAULT NULL');
        $this->addSql('ALTER TABLE phd2_guiding ADD rms_ra_arcsec DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE phd2_guiding ADD rms_dec_arcsec DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE phd2_guiding ADD total_rms_arcsec DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE phd2_guiding ADD samples JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE phd2_guiding DROP ra_errors');
        $this->addSql('ALTER TABLE phd2_guiding DROP dec_errors');
        $this->addSql('ALTER TABLE phd2_guiding DROP ra_corrections');
        $this->addSql('ALTER TABLE phd2_guiding DROP dec_corrections');
        $this->addSql('ALTER TABLE phd2_guiding DROP total_errors');
        $this->addSql('ALTER TABLE phd2_guiding DROP rms_ra');
        $this->addSql('ALTER TABLE phd2_guiding DROP rms_dec');
        $this->addSql('ALTER TABLE phd2_guiding DROP rms_total');
        $this->addSql('ALTER TABLE phd2_guiding ALTER session_id SET NOT NULL');
        $this->addSql('ALTER TABLE phd2_guiding ALTER source_path TYPE VARCHAR(1024)');
        $this->addSql('ALTER TABLE phd2_guiding ALTER section_index SET NOT NULL');
        $this->addSql('COMMENT ON COLUMN phd2_guiding.ended_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE phd2_guiding ADD CONSTRAINT FK_BB7344DE613FECDF FOREIGN KEY (session_id) REFERENCES session (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE phd2_guiding DROP CONSTRAINT fk_bb7344de613fecdf');
        $this->addSql('ALTER TABLE phd2_guiding ADD ra_errors JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE phd2_guiding ADD dec_errors JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE phd2_guiding ADD ra_corrections JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE phd2_guiding ADD dec_corrections JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE phd2_guiding ADD total_errors JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE phd2_guiding ADD rms_ra DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE phd2_guiding ADD rms_dec DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE phd2_guiding ADD rms_total DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE phd2_guiding DROP source_sha1');
        $this->addSql('ALTER TABLE phd2_guiding DROP ended_at');
        $this->addSql('ALTER TABLE phd2_guiding DROP mount');
        $this->addSql('ALTER TABLE phd2_guiding DROP pixel_scale_arcsec_per_px');
        $this->addSql('ALTER TABLE phd2_guiding DROP exposure_ms');
        $this->addSql('ALTER TABLE phd2_guiding DROP lock_position');
        $this->addSql('ALTER TABLE phd2_guiding DROP hfd_px');
        $this->addSql('ALTER TABLE phd2_guiding DROP frame_count');
        $this->addSql('ALTER TABLE phd2_guiding DROP drop_count');
        $this->addSql('ALTER TABLE phd2_guiding DROP rms_ra_arcsec');
        $this->addSql('ALTER TABLE phd2_guiding DROP rms_dec_arcsec');
        $this->addSql('ALTER TABLE phd2_guiding DROP total_rms_arcsec');
        $this->addSql('ALTER TABLE phd2_guiding DROP samples');
        $this->addSql('ALTER TABLE phd2_guiding ALTER session_id DROP NOT NULL');
        $this->addSql('ALTER TABLE phd2_guiding ALTER source_path TYPE VARCHAR(512)');
        $this->addSql('ALTER TABLE phd2_guiding ALTER section_index DROP NOT NULL');
        $this->addSql('ALTER TABLE phd2_guiding ADD CONSTRAINT fk_bb7344de613fecdf FOREIGN KEY (session_id) REFERENCES session (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
