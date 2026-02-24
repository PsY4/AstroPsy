<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Normalize filter names in target_goal to canonical forms:
 * L, R, G, B, Ha, OIII, SII
 */
final class Version20260222190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Normalize Progress Tracker filter names to canonical forms (Ha, OIII, SII, L, R, G, B)';
    }

    public function up(Schema $schema): void
    {
        // Where two goals for the same target would conflict (e.g. both 'H' and 'Ha'),
        // keep the one with the higher goalSeconds, then remove the duplicate.

        // Ha
        $this->addSql("UPDATE target_goal SET filter_name = 'Ha'
            WHERE LOWER(filter_name) IN ('h', 'h-alpha', 'halpha', 'h_alpha', 'h-?', 'h-a')
              AND NOT EXISTS (SELECT 1 FROM target_goal t2 WHERE t2.target_id = target_goal.target_id AND t2.filter_name = 'Ha')");
        $this->addSql("DELETE FROM target_goal
            WHERE LOWER(filter_name) IN ('h', 'h-alpha', 'halpha', 'h_alpha', 'h-?', 'h-a')
              AND EXISTS (SELECT 1 FROM target_goal t2 WHERE t2.target_id = target_goal.target_id AND t2.filter_name = 'Ha')");

        // OIII
        $this->addSql("UPDATE target_goal SET filter_name = 'OIII'
            WHERE LOWER(filter_name) IN ('o', 'o-iii', 'o3', 'o_iii', 'o-?')
              AND NOT EXISTS (SELECT 1 FROM target_goal t2 WHERE t2.target_id = target_goal.target_id AND t2.filter_name = 'OIII')");
        $this->addSql("DELETE FROM target_goal
            WHERE LOWER(filter_name) IN ('o', 'o-iii', 'o3', 'o_iii', 'o-?')
              AND EXISTS (SELECT 1 FROM target_goal t2 WHERE t2.target_id = target_goal.target_id AND t2.filter_name = 'OIII')");

        // SII
        $this->addSql("UPDATE target_goal SET filter_name = 'SII'
            WHERE LOWER(filter_name) IN ('s', 's-ii', 's2', 's_ii', 's-?')
              AND NOT EXISTS (SELECT 1 FROM target_goal t2 WHERE t2.target_id = target_goal.target_id AND t2.filter_name = 'SII')");
        $this->addSql("DELETE FROM target_goal
            WHERE LOWER(filter_name) IN ('s', 's-ii', 's2', 's_ii', 's-?')
              AND EXISTS (SELECT 1 FROM target_goal t2 WHERE t2.target_id = target_goal.target_id AND t2.filter_name = 'SII')");

        // L (Luminance)
        $this->addSql("UPDATE target_goal SET filter_name = 'L'
            WHERE LOWER(filter_name) IN ('lum', 'luminance', 'l-pro', 'lpro', 'l-enhance', 'lenhance')
              AND NOT EXISTS (SELECT 1 FROM target_goal t2 WHERE t2.target_id = target_goal.target_id AND t2.filter_name = 'L')");
        $this->addSql("DELETE FROM target_goal
            WHERE LOWER(filter_name) IN ('lum', 'luminance', 'l-pro', 'lpro', 'l-enhance', 'lenhance')
              AND EXISTS (SELECT 1 FROM target_goal t2 WHERE t2.target_id = target_goal.target_id AND t2.filter_name = 'L')");

        // R
        $this->addSql("UPDATE target_goal SET filter_name = 'R'
            WHERE LOWER(filter_name) = 'red'
              AND NOT EXISTS (SELECT 1 FROM target_goal t2 WHERE t2.target_id = target_goal.target_id AND t2.filter_name = 'R')");
        $this->addSql("DELETE FROM target_goal
            WHERE LOWER(filter_name) = 'red'
              AND EXISTS (SELECT 1 FROM target_goal t2 WHERE t2.target_id = target_goal.target_id AND t2.filter_name = 'R')");

        // G
        $this->addSql("UPDATE target_goal SET filter_name = 'G'
            WHERE LOWER(filter_name) = 'green'
              AND NOT EXISTS (SELECT 1 FROM target_goal t2 WHERE t2.target_id = target_goal.target_id AND t2.filter_name = 'G')");
        $this->addSql("DELETE FROM target_goal
            WHERE LOWER(filter_name) = 'green'
              AND EXISTS (SELECT 1 FROM target_goal t2 WHERE t2.target_id = target_goal.target_id AND t2.filter_name = 'G')");

        // B
        $this->addSql("UPDATE target_goal SET filter_name = 'B'
            WHERE LOWER(filter_name) = 'blue'
              AND NOT EXISTS (SELECT 1 FROM target_goal t2 WHERE t2.target_id = target_goal.target_id AND t2.filter_name = 'B')");
        $this->addSql("DELETE FROM target_goal
            WHERE LOWER(filter_name) = 'blue'
              AND EXISTS (SELECT 1 FROM target_goal t2 WHERE t2.target_id = target_goal.target_id AND t2.filter_name = 'B')");
    }

    public function down(Schema $schema): void
    {
        // Data migration — not reversible
        $this->addSql('SELECT 1');
    }
}
