<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenant;

use App\Tenant\Tenant;
use App\Tenant\TenantDatabaseManager;
use App\Tenant\TenantMigrator;
use App\Tenant\TenantRepository;
use PHPUnit\Framework\TestCase;

final class TenantMigratorTest extends TestCase
{
    private const APP_SECRET = 'migrator_test_secret_32_chars!!';

    private TenantRepository $repo;
    private TenantDatabaseManager $dbManager;
    private string $migrationsDir;

    #[\Override]
    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec(TenantRepository::SCHEMA_SQL);

        $this->repo = new TenantRepository($pdo);
        $this->dbManager = new TenantDatabaseManager(self::APP_SECRET);

        // Create a temp directory for migrations
        $this->migrationsDir = sys_get_temp_dir() . '/wctng_test_migrations_' . bin2hex(random_bytes(4));
        mkdir($this->migrationsDir);
    }

    #[\Override]
    protected function tearDown(): void
    {
        // Cleanup migration files
        $files = glob($this->migrationsDir . '/*');
        if (\is_array($files)) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
        rmdir($this->migrationsDir);
    }

    private function createTenant(string $slug): Tenant
    {
        $tenant = new Tenant(0, $slug, ucfirst($slug), '', ':memory:', '', '', 'free', 'active');
        $id = $this->repo->save($tenant);
        $found = $this->repo->findBySlug($slug);
        $this->assertNotNull($found);
        return $found;
    }

    private function writeMigration(string $name, string $sql): void
    {
        file_put_contents($this->migrationsDir . '/' . $name . '.sql', $sql);
    }

    public function testNoMigrationsReturnsZero(): void
    {
        $tenant = $this->createTenant('empty-co');
        $migrator = new TenantMigrator($this->repo, $this->dbManager, $this->migrationsDir);

        $result = $migrator->migrateTenant($tenant);

        $this->assertSame('empty-co', $result['slug']);
        $this->assertSame(0, $result['applied']);
        $this->assertNull($result['error']);
    }

    public function testAppliesMigration(): void
    {
        $this->writeMigration('001_create_test', 'CREATE TABLE test_table (id INTEGER PRIMARY KEY)');

        $tenant = $this->createTenant('migrate-co');
        $migrator = new TenantMigrator($this->repo, $this->dbManager, $this->migrationsDir);

        $result = $migrator->migrateTenant($tenant);

        $this->assertSame(1, $result['applied']);
        $this->assertNull($result['error']);

        // Verify table was created
        $pdo = $this->dbManager->getConnection($tenant);
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='test_table'");
        $this->assertNotFalse($stmt);
        $this->assertNotFalse($stmt->fetch());
    }

    public function testSkipsAlreadyApplied(): void
    {
        $this->writeMigration('001_create_test', 'CREATE TABLE test_table (id INTEGER PRIMARY KEY)');

        $tenant = $this->createTenant('skip-co');
        $migrator = new TenantMigrator($this->repo, $this->dbManager, $this->migrationsDir);

        // First run: applies
        $r1 = $migrator->migrateTenant($tenant);
        $this->assertSame(1, $r1['applied']);

        // Second run: skips
        $r2 = $migrator->migrateTenant($tenant);
        $this->assertSame(0, $r2['applied']);
        $this->assertSame(1, $r2['skipped']);
    }

    public function testMigrateAllIteratesTenants(): void
    {
        $this->writeMigration('001_create_test', 'CREATE TABLE test_table (id INTEGER PRIMARY KEY)');

        $this->createTenant('all-aaa');
        $this->createTenant('all-bbb');

        $migrator = new TenantMigrator($this->repo, $this->dbManager, $this->migrationsDir);
        $results = $migrator->migrateAll();

        $this->assertCount(2, $results);
        $this->assertSame(1, $results[0]['applied']);
        $this->assertSame(1, $results[1]['applied']);
    }

    public function testMigrateAllSkipsSuspended(): void
    {
        $this->writeMigration('001_test', 'CREATE TABLE t (id INTEGER PRIMARY KEY)');

        $this->createTenant('active-co');
        $this->repo->save(new Tenant(0, 'frozen-co', 'Frozen', '', ':memory:', '', '', 'free', 'suspended'));

        $migrator = new TenantMigrator($this->repo, $this->dbManager, $this->migrationsDir);
        $results = $migrator->migrateAll();

        $this->assertCount(2, $results);

        $activeResult = $results[0]['slug'] === 'active-co' ? $results[0] : $results[1];
        $frozenResult = $results[0]['slug'] === 'frozen-co' ? $results[0] : $results[1];

        $this->assertSame(1, $activeResult['applied']);
        $this->assertNotNull($frozenResult['error']);
        $this->assertStringContainsString('Skipped', (string) $frozenResult['error']);
    }

    public function testHandlesConnectionFailure(): void
    {
        $this->writeMigration('001_test', 'CREATE TABLE t (id INTEGER PRIMARY KEY)');

        $badTenant = new Tenant(99, 'bad-host', 'Bad', 'nonexistent.invalid', 'nodb', 'nobody', '', 'free', 'active');

        $migrator = new TenantMigrator($this->repo, $this->dbManager, $this->migrationsDir);
        $result = $migrator->migrateTenant($badTenant);

        $this->assertSame(0, $result['applied']);
        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('Unable to connect', $result['error']);
    }

    public function testAvailableCount(): void
    {
        $this->writeMigration('001_first', 'SELECT 1');
        $this->writeMigration('002_second', 'SELECT 1');

        $migrator = new TenantMigrator($this->repo, $this->dbManager, $this->migrationsDir);
        $this->assertSame(2, $migrator->availableCount());
    }
}
