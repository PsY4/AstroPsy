<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260222150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add wishlist boolean to target';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE target ADD wishlist BOOLEAN DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE target DROP COLUMN wishlist');
    }
}
