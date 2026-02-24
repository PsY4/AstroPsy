<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260222140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add target_goal table (Progress Tracker)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE target_goal (
            id SERIAL PRIMARY KEY,
            target_id INTEGER NOT NULL REFERENCES target(id) ON DELETE CASCADE,
            filter_name VARCHAR(32) NOT NULL,
            goal_seconds INTEGER NOT NULL DEFAULT 0,
            CONSTRAINT uq_target_goal UNIQUE (target_id, filter_name)
        )');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE target_goal');
    }
}
