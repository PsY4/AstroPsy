<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260224100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Setup: add NINA sequencer fields (imaging_type, camera params, filters_config)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE setup ADD imaging_type VARCHAR(4) NOT NULL DEFAULT 'MONO'");
        $this->addSql('ALTER TABLE setup ADD camera_gain INT DEFAULT NULL');
        $this->addSql('ALTER TABLE setup ADD camera_offset INT DEFAULT NULL');
        $this->addSql('ALTER TABLE setup ADD camera_cooling_temp DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE setup ADD camera_binning INT DEFAULT NULL');
        $this->addSql('ALTER TABLE setup ADD dither_every INT DEFAULT NULL');
        $this->addSql('ALTER TABLE setup ADD filters_config JSONB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE setup DROP imaging_type');
        $this->addSql('ALTER TABLE setup DROP camera_gain');
        $this->addSql('ALTER TABLE setup DROP camera_offset');
        $this->addSql('ALTER TABLE setup DROP camera_cooling_temp');
        $this->addSql('ALTER TABLE setup DROP camera_binning');
        $this->addSql('ALTER TABLE setup DROP dither_every');
        $this->addSql('ALTER TABLE setup DROP filters_config');
    }
}
