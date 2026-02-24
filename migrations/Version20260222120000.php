<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260222120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add altitude_horizon to observatory';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE observatory ADD altitude_horizon DOUBLE PRECISION DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE observatory DROP COLUMN altitude_horizon');
    }
}
