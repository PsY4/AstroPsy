<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260227100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create autofocus_log table for NINA/Hocus Focus autofocus data';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE autofocus_log (
            id SERIAL PRIMARY KEY,
            session_id INT NOT NULL REFERENCES session(id) ON DELETE CASCADE,
            source_path VARCHAR(1024) NOT NULL,
            run_folder VARCHAR(255) NOT NULL,
            attempt_number INT NOT NULL,
            timestamp TIMESTAMP(0) WITHOUT TIME ZONE,
            filter VARCHAR(32),
            temperature DOUBLE PRECISION,
            method VARCHAR(32),
            fitting VARCHAR(32),
            duration_seconds INT,
            initial_position DOUBLE PRECISION,
            initial_hfr DOUBLE PRECISION,
            calculated_position DOUBLE PRECISION,
            calculated_hfr DOUBLE PRECISION,
            final_hfr DOUBLE PRECISION,
            r_squared DOUBLE PRECISION,
            measure_points JSONB,
            fittings JSONB,
            focuser_name VARCHAR(64),
            star_detector_name VARCHAR(64),
            backlash_model VARCHAR(32),
            backlash_in INT,
            backlash_out INT,
            success BOOLEAN NOT NULL DEFAULT FALSE,
            hidden BOOLEAN,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
        )');

        $this->addSql('CREATE INDEX idx_autofocus_log_session ON autofocus_log (session_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS autofocus_log');
    }
}
