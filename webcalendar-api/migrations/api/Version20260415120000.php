<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Performance optimization indexes — ported from the legacy
 * `migrations/003_performance_optimization.sql` which used MySQL stored
 * procedures to make the script idempotent under the `mysql` CLI. The
 * Doctrine runner already guards against re-running a migration via its
 * version table, so we drop the procedure scaffolding and emit plain
 * `CREATE INDEX` / `ALTER TABLE ... ADD FULLTEXT` statements.
 *
 * Kept as a single migration because the indexes were shipped together
 * and `down()` must be a clean inverse.
 */
final class Version20260415120000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Performance indexes on webcal_entry and related tables (ported from 003_performance_optimization.sql)';
    }

    /** @var list<array{table: string, name: string, cols: string}> */
    private const INDEXES = [
        // Core date range queries
        ['table' => 'webcal_entry', 'name' => 'idx_entry_date', 'cols' => 'cal_date'],
        ['table' => 'webcal_entry', 'name' => 'idx_entry_create_by', 'cols' => 'cal_create_by'],
        ['table' => 'webcal_entry', 'name' => 'idx_entry_user_date', 'cols' => 'cal_create_by, cal_date'],
        ['table' => 'webcal_entry', 'name' => 'idx_entry_user_date_time', 'cols' => 'cal_create_by, cal_date, cal_time'],
        ['table' => 'webcal_entry', 'name' => 'idx_entry_access', 'cols' => 'cal_access'],
        ['table' => 'webcal_entry', 'name' => 'idx_entry_mod_date', 'cols' => 'cal_mod_date'],

        // Recurrence subquery
        ['table' => 'webcal_entry_repeats', 'name' => 'idx_entry_repeats_cal', 'cols' => 'cal_id'],
        ['table' => 'webcal_entry_repeats_not', 'name' => 'idx_entry_repeats_not_cal', 'cols' => 'cal_id'],

        // Category lookups
        ['table' => 'webcal_entry_categories', 'name' => 'idx_entry_categories_cal', 'cols' => 'cal_id'],
        ['table' => 'webcal_entry_categories', 'name' => 'idx_entry_categories_cat', 'cols' => 'cat_id'],

        // User/participant lookups
        ['table' => 'webcal_entry_user', 'name' => 'idx_entry_user_cal', 'cols' => 'cal_id, cal_login'],
        ['table' => 'webcal_entry_user', 'name' => 'idx_entry_user_login', 'cols' => 'cal_login, cal_id'],

        // Preference and config lookups
        ['table' => 'webcal_user_pref', 'name' => 'idx_user_pref_login', 'cols' => 'cal_login'],
        ['table' => 'webcal_config', 'name' => 'idx_config_name', 'cols' => 'cal_setting'],

        // Layer lookups
        ['table' => 'webcal_user_layers', 'name' => 'idx_user_layers_login', 'cols' => 'cal_login'],
    ];

    private const FULLTEXT_TABLE = 'webcal_entry';
    private const FULLTEXT_NAME = 'idx_entry_fulltext';
    private const FULLTEXT_COLS = 'cal_name, cal_description';

    #[\Override]
    public function up(Schema $schema): void
    {
        foreach (self::INDEXES as $idx) {
            $this->addSql("CREATE INDEX {$idx['name']} ON {$idx['table']} ({$idx['cols']})");
        }

        $this->addSql(
            'ALTER TABLE ' . self::FULLTEXT_TABLE
                . ' ADD FULLTEXT INDEX ' . self::FULLTEXT_NAME . ' (' . self::FULLTEXT_COLS . ')',
        );
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        foreach (array_reverse(self::INDEXES) as $idx) {
            $this->addSql("DROP INDEX {$idx['name']} ON {$idx['table']}");
        }

        $this->addSql('DROP INDEX ' . self::FULLTEXT_NAME . ' ON ' . self::FULLTEXT_TABLE);
    }
}
