<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260222160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add optical fields to setup (sensor dimensions, pixel size, focal length)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE setup ADD sensor_w_px INT DEFAULT NULL');
        $this->addSql('ALTER TABLE setup ADD sensor_h_px INT DEFAULT NULL');
        $this->addSql('ALTER TABLE setup ADD pixel_size_um DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE setup ADD focal_mm DOUBLE PRECISION DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE setup DROP COLUMN sensor_w_px');
        $this->addSql('ALTER TABLE setup DROP COLUMN sensor_h_px');
        $this->addSql('ALTER TABLE setup DROP COLUMN pixel_size_um');
        $this->addSql('ALTER TABLE setup DROP COLUMN focal_mm');
    }
}
