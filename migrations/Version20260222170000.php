<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260222170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create wish_list_entry table for framing assistant';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE wish_list_entry (
            id SERIAL PRIMARY KEY,
            target_id INT NOT NULL REFERENCES target(id) ON DELETE CASCADE,
            setup_id INT DEFAULT NULL REFERENCES setup(id) ON DELETE SET NULL,
            ra_framing DOUBLE PRECISION DEFAULT NULL,
            dec_framing DOUBLE PRECISION DEFAULT NULL,
            rotation_angle DOUBLE PRECISION DEFAULT NULL,
            UNIQUE (target_id)
        )');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE wish_list_entry');
    }
}
