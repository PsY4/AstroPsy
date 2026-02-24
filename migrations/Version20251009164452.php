<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251009164452 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE phd2_calibration (id INT NOT NULL, session_id INT NOT NULL, source_path VARCHAR(1024) DEFAULT NULL, source_sha1 VARCHAR(40) DEFAULT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, ended_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, headers JSON DEFAULT NULL, mount VARCHAR(255) DEFAULT NULL, pixel_scale_arcsec_per_px DOUBLE PRECISION DEFAULT NULL, lock_position JSON DEFAULT NULL, west_angle_deg DOUBLE PRECISION DEFAULT NULL, west_rate_px_per_sec DOUBLE PRECISION DEFAULT NULL, west_parity VARCHAR(32) DEFAULT NULL, north_angle_deg DOUBLE PRECISION DEFAULT NULL, north_rate_px_per_sec DOUBLE PRECISION DEFAULT NULL, north_parity VARCHAR(32) DEFAULT NULL, orthogonality_deg DOUBLE PRECISION DEFAULT NULL, points_west JSON DEFAULT NULL, points_east JSON DEFAULT NULL, points_north JSON DEFAULT NULL, points_south JSON DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_9594E9FC613FECDF ON phd2_calibration (session_id)');
        $this->addSql('COMMENT ON COLUMN phd2_calibration.started_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN phd2_calibration.ended_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN phd2_calibration.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN phd2_calibration.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE phd2_calibration ADD CONSTRAINT FK_9594E9FC613FECDF FOREIGN KEY (session_id) REFERENCES session (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP SEQUENCE phd2_calibration_id_seq CASCADE');
        $this->addSql('ALTER TABLE phd2_calibration DROP CONSTRAINT FK_9594E9FC613FECDF');
        $this->addSql('DROP TABLE phd2_calibration');
        $this->addSql('ALTER TABLE exposure ALTER format SET NOT NULL');
    }
}
