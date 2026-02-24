<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260222210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Setup: add overhead planning fields (slew, autofocus, meridian flip, min shoot time)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE setup ADD slew_time_min INT DEFAULT NULL');
        $this->addSql('ALTER TABLE setup ADD autofocus_time_min INT DEFAULT NULL');
        $this->addSql('ALTER TABLE setup ADD autofocus_interval_min INT DEFAULT NULL');
        $this->addSql('ALTER TABLE setup ADD meridian_flip_time_min INT DEFAULT NULL');
        $this->addSql('ALTER TABLE setup ADD min_shoot_time_min INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE setup DROP COLUMN slew_time_min');
        $this->addSql('ALTER TABLE setup DROP COLUMN autofocus_time_min');
        $this->addSql('ALTER TABLE setup DROP COLUMN autofocus_interval_min');
        $this->addSql('ALTER TABLE setup DROP COLUMN meridian_flip_time_min');
        $this->addSql('ALTER TABLE setup DROP COLUMN min_shoot_time_min');
    }
}
