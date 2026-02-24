<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251006165247 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE target ADD thumbnail_url VARCHAR(512) DEFAULT NULL');
        $this->addSql('ALTER TABLE target ADD constellation VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE target ADD visual_mag DOUBLE PRECISION DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE target DROP thumbnail_url');
        $this->addSql('ALTER TABLE target DROP constellation');
        $this->addSql('ALTER TABLE target DROP visual_mag');
    }
}
