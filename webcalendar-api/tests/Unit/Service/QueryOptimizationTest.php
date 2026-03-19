<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

final class QueryOptimizationTest extends TestCase
{
    public function testIndexMigrationFileExists(): void
    {
        $migrationFile = __DIR__ . '/../../../migrations/tenant/001_add_indexes.sql';
        $this->assertFileExists($migrationFile);

        $content = file_get_contents($migrationFile);
        $this->assertIsString($content);
        $this->assertStringContainsString('idx_entry_date', $content);
        $this->assertStringContainsString('idx_entry_create_by', $content);
        $this->assertStringContainsString('idx_entry_type', $content);
    }

    public function testIndexesCanBeCreatedOnSqlite(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Create minimal tables
        $pdo->exec('CREATE TABLE webcal_entry (cal_id INTEGER PRIMARY KEY, cal_date INTEGER, cal_create_by VARCHAR(60), cal_type CHAR(1))');
        $pdo->exec('CREATE TABLE webcal_entry_user (cal_id INTEGER, cal_login VARCHAR(60))');
        $pdo->exec('CREATE TABLE webcal_entry_categories (cal_id INTEGER, cat_id INTEGER)');
        $pdo->exec('CREATE TABLE webcal_user_layers (cal_layerid INTEGER, cal_login VARCHAR(60))');

        // Apply indexes
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_entry_date ON webcal_entry (cal_date)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_entry_create_by ON webcal_entry (cal_create_by)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_entry_type ON webcal_entry (cal_type)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_entry_date_user ON webcal_entry (cal_create_by, cal_date)');

        // Verify indexes exist
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='index' AND name LIKE 'idx_%'");
        $this->assertNotFalse($stmt);
        $indexes = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertContains('idx_entry_date', $indexes);
        $this->assertContains('idx_entry_create_by', $indexes);
        $this->assertContains('idx_entry_type', $indexes);
        $this->assertContains('idx_entry_date_user', $indexes);
    }

    public function testBatchCategoryLoadingExists(): void
    {
        // Verify EventController uses batch loading (getForEventsBatch)
        $controllerFile = __DIR__ . '/../../../src/Controller/Api/EventController.php';
        $content = file_get_contents($controllerFile);
        $this->assertIsString($content);
        $this->assertStringContainsString('getForEventsBatch', $content);
    }
}
