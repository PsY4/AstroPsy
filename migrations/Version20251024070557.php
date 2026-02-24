<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251024070557 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE exposure ALTER format TYPE VARCHAR(32)');
        $this->addSql('ALTER TABLE exposure ALTER filter_name TYPE VARCHAR(32)');
        $this->addSql('ALTER TABLE exposure ALTER type TYPE VARCHAR(32)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE exposure ALTER format TYPE VARCHAR(8)');
        $this->addSql('ALTER TABLE exposure ALTER type TYPE VARCHAR(8)');
        $this->addSql('ALTER TABLE exposure ALTER filter_name TYPE VARCHAR(16)');
    }
}
