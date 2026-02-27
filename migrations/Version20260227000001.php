<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260227000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create wbpp_log table for WBPP (PixInsight) log data';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE wbpp_log (
            id SERIAL PRIMARY KEY,
            session_id INT NOT NULL REFERENCES session(id) ON DELETE CASCADE,
            source_path VARCHAR(1024) NOT NULL,
            source_sha1 VARCHAR(40),
            pi_version VARCHAR(64),
            wbpp_version VARCHAR(64),
            started_at TIMESTAMP(0) WITHOUT TIME ZONE,
            duration_seconds INT,
            calibration_summary JSONB,
            filter_groups JSONB,
            frames JSONB,
            integration_results JSONB,
            hidden BOOLEAN,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
        )');

        $this->addSql('CREATE INDEX idx_wbpp_log_session ON wbpp_log (session_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS wbpp_log');
    }
}
