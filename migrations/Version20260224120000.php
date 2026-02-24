<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260224120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create notification table for persistent evening alerts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE notification (
            id SERIAL PRIMARY KEY,
            type VARCHAR(50) NOT NULL,
            title VARCHAR(255) NOT NULL,
            summary VARCHAR(500) NOT NULL DEFAULT '',
            data JSON DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            read_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL
        )");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE notification');
    }
}
