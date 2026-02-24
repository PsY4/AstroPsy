<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260222200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Session : ajout exclude_from_progress (boolean, default false)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE session ADD exclude_from_progress BOOLEAN NOT NULL DEFAULT FALSE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE session DROP COLUMN exclude_from_progress');
    }
}
