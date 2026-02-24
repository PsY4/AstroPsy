<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250811223425 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE export_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE exposure_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE log_file_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE master_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE metric_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE session_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE target_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE export (id INT NOT NULL, session_id INT DEFAULT NULL, type VARCHAR(32) NOT NULL, path VARCHAR(1024) NOT NULL, hash VARCHAR(64) NOT NULL, metadata JSON DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_428C1694613FECDF ON export (session_id)');
        $this->addSql('CREATE TABLE exposure (id INT NOT NULL, session_id INT DEFAULT NULL, path VARCHAR(1024) NOT NULL, hash VARCHAR(64) NOT NULL, format VARCHAR(8) NOT NULL, filter_name VARCHAR(16) DEFAULT NULL, exposure_s DOUBLE PRECISION DEFAULT NULL, sensor_temp DOUBLE PRECISION DEFAULT NULL, date_taken TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, raw_header JSON DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_398F29CDD1B862B8 ON exposure (hash)');
        $this->addSql('CREATE INDEX IDX_398F29CD613FECDF ON exposure (session_id)');
        $this->addSql('CREATE TABLE log_file (id INT NOT NULL, session_id INT DEFAULT NULL, source VARCHAR(32) NOT NULL, path VARCHAR(1024) NOT NULL, hash VARCHAR(64) NOT NULL, parsed BOOLEAN NOT NULL, parse_errors JSON DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_9DF1D865613FECDF ON log_file (session_id)');
        $this->addSql('CREATE TABLE master (id INT NOT NULL, session_id INT DEFAULT NULL, type VARCHAR(32) NOT NULL, filter_name VARCHAR(16) DEFAULT NULL, path VARCHAR(1024) NOT NULL, hash VARCHAR(64) NOT NULL, header JSON DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_2D09A3D6613FECDF ON master (session_id)');
        $this->addSql('CREATE TABLE metric (id INT NOT NULL, session_id INT DEFAULT NULL, kind VARCHAR(32) NOT NULL, t TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, value DOUBLE PRECISION NOT NULL, extras JSON DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_87D62EE3613FECDF ON metric (session_id)');
        $this->addSql('CREATE TABLE session (id INT NOT NULL, target_id INT DEFAULT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, ended_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, site VARCHAR(255) DEFAULT NULL, gear_profile JSON DEFAULT NULL, notes TEXT DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_D044D5D4158E0B66 ON session (target_id)');
        $this->addSql('CREATE TABLE target (id INT NOT NULL, name VARCHAR(255) NOT NULL, ra DOUBLE PRECISION DEFAULT NULL, dec DOUBLE PRECISION DEFAULT NULL, catalog_ids JSON DEFAULT NULL, notes TEXT DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('ALTER TABLE export ADD CONSTRAINT FK_428C1694613FECDF FOREIGN KEY (session_id) REFERENCES session (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE exposure ADD CONSTRAINT FK_398F29CD613FECDF FOREIGN KEY (session_id) REFERENCES session (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE log_file ADD CONSTRAINT FK_9DF1D865613FECDF FOREIGN KEY (session_id) REFERENCES session (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE master ADD CONSTRAINT FK_2D09A3D6613FECDF FOREIGN KEY (session_id) REFERENCES session (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE metric ADD CONSTRAINT FK_87D62EE3613FECDF FOREIGN KEY (session_id) REFERENCES session (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE session ADD CONSTRAINT FK_D044D5D4158E0B66 FOREIGN KEY (target_id) REFERENCES target (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('CREATE SCHEMA timescaledb_information');
        $this->addSql('CREATE SCHEMA timescaledb_experimental');
        $this->addSql('CREATE SCHEMA _timescaledb_internal');
        $this->addSql('CREATE SCHEMA _timescaledb_functions');
        $this->addSql('CREATE SCHEMA _timescaledb_debug');
        $this->addSql('CREATE SCHEMA _timescaledb_config');
        $this->addSql('CREATE SCHEMA _timescaledb_catalog');
        $this->addSql('CREATE SCHEMA _timescaledb_cache');
        $this->addSql('DROP SEQUENCE export_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE exposure_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE log_file_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE master_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE metric_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE session_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE target_id_seq CASCADE');
        $this->addSql('CREATE SEQUENCE _timescaledb_catalog.hypertable_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE _timescaledb_catalog.tablespace_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE _timescaledb_catalog.dimension_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE _timescaledb_catalog.dimension_slice_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE _timescaledb_catalog.chunk_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE _timescaledb_catalog.chunk_constraint_name INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE _timescaledb_catalog.chunk_column_stats_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE _timescaledb_catalog.continuous_agg_migrate_plan_step_step_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE _timescaledb_config.bgw_job_id_seq INCREMENT BY 1 MINVALUE 1000 START 1000');
        $this->addSql('CREATE SEQUENCE _timescaledb_internal.bgw_job_stat_history_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('ALTER TABLE export DROP CONSTRAINT FK_428C1694613FECDF');
        $this->addSql('ALTER TABLE exposure DROP CONSTRAINT FK_398F29CD613FECDF');
        $this->addSql('ALTER TABLE log_file DROP CONSTRAINT FK_9DF1D865613FECDF');
        $this->addSql('ALTER TABLE master DROP CONSTRAINT FK_2D09A3D6613FECDF');
        $this->addSql('ALTER TABLE metric DROP CONSTRAINT FK_87D62EE3613FECDF');
        $this->addSql('ALTER TABLE session DROP CONSTRAINT FK_D044D5D4158E0B66');
        $this->addSql('DROP TABLE export');
        $this->addSql('DROP TABLE exposure');
        $this->addSql('DROP TABLE log_file');
        $this->addSql('DROP TABLE master');
        $this->addSql('DROP TABLE metric');
        $this->addSql('DROP TABLE session');
        $this->addSql('DROP TABLE target');
    }
}
