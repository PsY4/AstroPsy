<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Convert absolute paths to relative paths (strip sessions root prefix).
 * This makes the DB portable when sessions root changes.
 */
final class Version20260224180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Convert absolute paths to relative (strip sessions root prefix)';
    }

    public function up(Schema $schema): void
    {
        $prefix = rtrim(getenv('SESSIONS_ROOT') ?: '/app/data/sessions', '/') . '/';
        $len = strlen($prefix) + 1; // +1 for 1-based SUBSTR in PostgreSQL

        // Target.path
        $this->addSql(
            "UPDATE target SET path = SUBSTR(path, $len) WHERE path LIKE '$prefix%'"
        );

        // Session.path
        $this->addSql(
            "UPDATE session SET path = SUBSTR(path, $len) WHERE path LIKE '$prefix%'"
        );

        // Exposure.path
        $this->addSql(
            "UPDATE exposure SET path = SUBSTR(path, $len) WHERE path LIKE '$prefix%'"
        );

        // Master.path
        $this->addSql(
            "UPDATE master SET path = SUBSTR(path, $len) WHERE path LIKE '$prefix%'"
        );

        // Export.path
        $this->addSql(
            "UPDATE export SET path = SUBSTR(path, $len) WHERE path LIKE '$prefix%'"
        );

        // Phd2Calibration.source_path
        $this->addSql(
            "UPDATE phd2_calibration SET source_path = SUBSTR(source_path, $len) WHERE source_path LIKE '$prefix%'"
        );

        // Phd2Guiding.source_path
        $this->addSql(
            "UPDATE phd2_guiding SET source_path = SUBSTR(source_path, $len) WHERE source_path LIKE '$prefix%'"
        );
    }

    public function down(Schema $schema): void
    {
        $prefix = rtrim(getenv('SESSIONS_ROOT') ?: '/app/data/sessions', '/') . '/';

        $this->addSql("UPDATE target SET path = '$prefix' || path WHERE path NOT LIKE '/%'");
        $this->addSql("UPDATE session SET path = '$prefix' || path WHERE path NOT LIKE '/%'");
        $this->addSql("UPDATE exposure SET path = '$prefix' || path WHERE path NOT LIKE '/%'");
        $this->addSql("UPDATE master SET path = '$prefix' || path WHERE path NOT LIKE '/%'");
        $this->addSql("UPDATE export SET path = '$prefix' || path WHERE path NOT LIKE '/%'");
        $this->addSql("UPDATE phd2_calibration SET source_path = '$prefix' || source_path WHERE source_path NOT LIKE '/%'");
        $this->addSql("UPDATE phd2_guiding SET source_path = '$prefix' || source_path WHERE source_path NOT LIKE '/%'");
    }
}
