<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260224110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'WishListEntry: add filters_selected (JSON array of filter positions for NINA export)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wish_list_entry ADD COLUMN filters_selected JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wish_list_entry DROP COLUMN filters_selected');
    }
}
