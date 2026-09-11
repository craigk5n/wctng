<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenant;

use App\Tenant\Tenant;
use App\Tenant\TenantDatabaseManager;
use App\Tenant\TenantMigrator;
use App\Tenant\TenantPlan;
use App\Tenant\TenantRepository;
use App\Tenant\TenantStatus;
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

    /**
     * A tenant on its own SQLite file, so a table can be put there before the
     * migrator ever connects. The in-memory tenants used elsewhere start empty
     * on every connection.
     *
     * @var list<string>
     */
    private array $tenantFiles = [];

    private function createTenantOnDisk(string $slug): Tenant
    {
        $file = sys_get_temp_dir() . '/wctng_tenant_' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->tenantFiles[] = $file;

        $this->repo->save(
            new Tenant(0, $slug, ucfirst($slug), '', $file, '', '', TenantPlan::Free, TenantStatus::Active),
        );
        $found = $this->repo->findBySlug($slug);
        self::assertNotNull($found);

        return $found;
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

        foreach ($this->tenantFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private function createTenant(string $slug): Tenant
    {
        $tenant = new Tenant(0, $slug, ucfirst($slug), '', ':memory:', '', '', TenantPlan::Free, TenantStatus::Active);
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
        $this->repo->save(new Tenant(0, 'frozen-co', 'Frozen', '', ':memory:', '', '', TenantPlan::Free, TenantStatus::Suspended));

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

        $badTenant = new Tenant(99, 'bad-host', 'Bad', 'nonexistent.invalid', 'nodb', 'nobody', '', TenantPlan::Free, TenantStatus::Active);

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

    // ------------------------------------------ the whole result, not a field

    private function migrator(): TenantMigrator
    {
        return new TenantMigrator($this->repo, $this->dbManager, $this->migrationsDir);
    }

    public function testAConnectionFailureReportsCountsAndSlugTooNotJustTheError(): void
    {
        // Only 'applied' and 'error' were checked, so the slug and the skipped
        // count in this result could be anything at all -- and this is the
        // shape the control-plane report is built from.
        $this->writeMigration('001_test', 'CREATE TABLE t (id INTEGER PRIMARY KEY);');
        $badTenant = new Tenant(99, 'bad-host', 'Bad', 'nonexistent.invalid', 'nodb', 'nobody', '', TenantPlan::Free, TenantStatus::Active);

        $result = $this->migrator()->migrateTenant($badTenant);

        self::assertSame('bad-host', $result['slug']);
        self::assertSame(0, $result['applied']);
        self::assertSame(0, $result['skipped']);
        self::assertIsString($result['error']);
        self::assertStringContainsString('Unable to connect', $result['error']);
    }

    public function testASuspendedTenantReportsCountsAndSlugTooNotJustTheError(): void
    {
        $this->writeMigration('001_test', 'CREATE TABLE t (id INTEGER PRIMARY KEY);');
        $this->repo->save(new Tenant(0, 'frozen-co', 'Frozen', '', ':memory:', '', '', TenantPlan::Free, TenantStatus::Suspended));

        $results = $this->migrator()->migrateAll();

        self::assertSame(
            [['slug' => 'frozen-co', 'applied' => 0, 'skipped' => 0, 'error' => 'Skipped (suspended)']],
            $results,
        );
    }

    // -------------------------------------- a skip must not end the loop

    public function testASuspendedTenantDoesNotStopTheOnesBehindIt(): void
    {
        // findAll() orders by slug, so "aaa" sorts before "zzz": the suspended
        // tenant is reached first and the active one after it. With a break
        // there instead of a continue, one suspended tenant stops every
        // migration behind it in the alphabet -- and the report still looks
        // plausible, just shorter.
        $this->writeMigration('001_test', 'CREATE TABLE t (id INTEGER PRIMARY KEY);');
        $this->repo->save(new Tenant(0, 'aaa-frozen', 'Frozen', '', ':memory:', '', '', TenantPlan::Free, TenantStatus::Suspended));
        $this->createTenant('zzz-active');

        $results = $this->migrator()->migrateAll();

        self::assertCount(2, $results);
        self::assertSame('aaa-frozen', $results[0]['slug']);
        self::assertSame('Skipped (suspended)', $results[0]['error']);
        self::assertSame('zzz-active', $results[1]['slug']);
        self::assertSame(1, $results[1]['applied'], 'the active tenant behind it was still migrated');
    }

    public function testAnAlreadyAppliedMigrationDoesNotStopTheOnesAfterIt(): void
    {
        // With one migration on disk, continue and break are the same thing.
        // With two, a break at the first already-applied one means every later
        // migration is never applied -- and the tenant sits permanently one
        // version behind while the run reports success.
        $this->writeMigration('001_first', 'CREATE TABLE first_table (id INTEGER PRIMARY KEY);');
        $tenant = $this->createTenant('two-step');

        $first = $this->migrator()->migrateTenant($tenant);
        self::assertSame(1, $first['applied']);

        $this->writeMigration('002_second', 'CREATE TABLE second_table (id INTEGER PRIMARY KEY);');
        $second = $this->migrator()->migrateTenant($tenant);

        self::assertSame(1, $second['applied'], 'the new migration is applied');
        self::assertSame(1, $second['skipped'], 'the old one is skipped, not re-run');
        self::assertNull($second['error']);

        $tables = $this->dbManager->getConnection($tenant)
            ->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('first_table','second_table') ORDER BY name")
            ?->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['first_table', 'second_table'], $tables);
    }

    public function testEveryAppliedVersionIsRemembered(): void
    {
        // The applied-versions list is read back to decide what to skip. If it
        // only ever carries its first entry, every migration but the earliest
        // is re-applied on the next run -- which for a CREATE TABLE means the
        // run fails, and for an INSERT means silent duplication.
        $this->writeMigration('001_first', 'CREATE TABLE first_table (id INTEGER PRIMARY KEY);');
        $this->writeMigration('002_second', 'CREATE TABLE second_table (id INTEGER PRIMARY KEY);');
        $tenant = $this->createTenant('remember-co');

        self::assertSame(2, $this->migrator()->migrateTenant($tenant)['applied']);

        $again = $this->migrator()->migrateTenant($tenant);

        self::assertSame(0, $again['applied']);
        self::assertSame(2, $again['skipped'], 'both versions are remembered, not just the first');
        self::assertNull($again['error']);
    }

    // ------------------------------------------------ realistic migration files

    public function testAFileWrittenTheWaySqlFilesAreWrittenIsApplied(): void
    {
        // Every existing case used a single statement with no trailing
        // semicolon, which is the one shape that splits without leaving an
        // empty segment behind. A file written normally leaves one -- and
        // PDO::exec('') raises a ValueError, which the PDOException handler
        // around the loop does not catch, so it would escape migrateTenant()
        // entirely rather than being reported as a failed migration.
        $this->writeMigration('001_realistic', <<<'SQL'
            CREATE TABLE alpha (id INTEGER PRIMARY KEY);
            CREATE TABLE beta (id INTEGER PRIMARY KEY);
            SQL);
        $tenant = $this->createTenant('realistic-co');

        $result = $this->migrator()->migrateTenant($tenant);

        self::assertNull($result['error']);
        self::assertSame(1, $result['applied']);

        $tables = $this->dbManager->getConnection($tenant)
            ->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('alpha','beta') ORDER BY name")
            ?->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['alpha', 'beta'], $tables);
    }

    public function testAFailedStatementIsReportedAgainstItsVersion(): void
    {
        // A migration that cannot run has to name itself in the error, or an
        // operator staring at a fleet-wide report has no idea which file to
        // look at. The counts have to come back too: how far the run got
        // before it stopped is the difference between "nothing happened" and
        // "this tenant is now half migrated".
        $this->writeMigration('001_fine', 'CREATE TABLE fine (id INTEGER PRIMARY KEY);');
        $tenant = $this->createTenant('broken-co');
        self::assertSame(1, $this->migrator()->migrateTenant($tenant)['applied']);

        $this->writeMigration('002_broken', 'CREATE TABLE ((( ;');
        $result = $this->migrator()->migrateTenant($tenant);

        self::assertSame('broken-co', $result['slug']);
        self::assertSame(0, $result['applied']);
        self::assertSame(1, $result['skipped'], 'the version it had already applied is still counted');
        self::assertIsString($result['error']);
        self::assertStringStartsWith('Migration 002_broken failed:', $result['error']);
    }

    public function testAnUnreadableHistoryIsReportedRatherThanThrown(): void
    {
        // schema_migrations already exists, but not as this migrator writes it
        // -- another tool's table, say. CREATE TABLE IF NOT EXISTS accepts it
        // and the SELECT then finds no version column. The method promises a
        // summary, so it has to come back as an error rather than an exception.
        $tenant = $this->createTenantOnDisk('foreign-co');
        $seed = new \PDO('sqlite:' . (string) $tenant->dbName());
        $seed->exec('CREATE TABLE schema_migrations (id INTEGER PRIMARY KEY, name TEXT)');
        unset($seed);

        $this->writeMigration('001_add_widgets', 'CREATE TABLE widgets (id INTEGER)');
        $migrator = new TenantMigrator($this->repo, $this->dbManager, $this->migrationsDir);

        $result = $migrator->migrateTenant($tenant);

        self::assertSame('foreign-co', $result['slug']);
        self::assertSame(0, $result['applied']);
        self::assertSame(0, $result['skipped']);
        self::assertNotNull($result['error']);
        self::assertStringContainsString('Migration history unavailable', $result['error']);
    }

    public function testOneTenantWithAnUnreadableHistoryDoesNotStopTheRest(): void
    {
        // The whole point of migrateAll() returning a row per tenant: an
        // operator gets told which ones failed and the others still get
        // migrated. An exception out of migrateTenant() abandoned every
        // tenant sorted after the broken one.
        $broken = $this->createTenantOnDisk('aaa-broken');
        $seed = new \PDO('sqlite:' . (string) $broken->dbName());
        $seed->exec('CREATE TABLE schema_migrations (id INTEGER PRIMARY KEY, name TEXT)');
        unset($seed);

        $this->createTenant('zzz-healthy');

        $this->writeMigration('001_add_widgets', 'CREATE TABLE widgets (id INTEGER)');
        $migrator = new TenantMigrator($this->repo, $this->dbManager, $this->migrationsDir);

        $results = $migrator->migrateAll();
        $bySlug = array_column($results, null, 'slug');

        self::assertCount(2, $results);
        self::assertNotNull($bySlug['aaa-broken']['error']);
        self::assertSame(0, $bySlug['aaa-broken']['applied']);
        self::assertSame(0, $bySlug['aaa-broken']['skipped']);
        self::assertNull($bySlug['zzz-healthy']['error'], 'the healthy tenant should still have been migrated');
        self::assertSame(1, $bySlug['zzz-healthy']['applied']);
    }
}
