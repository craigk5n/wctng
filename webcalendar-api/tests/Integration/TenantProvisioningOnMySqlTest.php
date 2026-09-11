<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Security\PasswordHasher;
use App\Service\DatabaseDsn;
use App\Tenant\MySqlTenantDatabaseCreator;
use App\Tenant\TenantDatabaseCreator;
use App\Tenant\TenantDatabaseManager;
use App\Tenant\TenantProvisioner;
use App\Tenant\TenantRepository;
use PHPUnit\Framework\TestCase;

/**
 * Provisioning a tenant against a real MySQL server.
 *
 * Every other provisioning test runs the SQLite branch, which was the only
 * finished one: createMySqlDatabase() threw unconditionally, so provisioning
 * could not succeed on the driver hosted mode actually uses. This is the
 * acceptance test for that -- it creates a database, a login and a schema on
 * the server, and then logs the new tenant's admin in with the password the
 * caller was handed.
 *
 * Skipped unless TENANT_ADMIN_DATABASE_URL names a login that may CREATE
 * DATABASE and CREATE USER.
 */
final class TenantProvisioningOnMySqlTest extends TestCase
{
    private const APP_SECRET = 'mysql_provisioning_secret_32ch!!';

    private string $adminUrl = '';
    private \PDO $control;
    private TenantRepository $repo;
    private TenantDatabaseManager $dbManager;
    private string $slug = '';

    #[\Override]
    protected function setUp(): void
    {
        $url = getenv('TENANT_ADMIN_DATABASE_URL');
        if (!\is_string($url) || $url === '') {
            self::markTestSkipped('TENANT_ADMIN_DATABASE_URL is not set; no administrative MySQL login to provision with.');
        }

        $this->adminUrl = $url;
        $admin = DatabaseDsn::fromUrl($url);

        try {
            $this->control = new \PDO($admin->dsn, $admin->user, $admin->password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (\PDOException $e) {
            self::markTestSkipped('cannot reach MySQL with the administrative login: ' . $e->getMessage());
        }

        $this->control->exec(TenantRepository::SCHEMA_SQL_MYSQL);
        $this->repo = new TenantRepository($this->control);
        $this->dbManager = new TenantDatabaseManager(self::APP_SECRET);
        $this->slug = 'it' . bin2hex(random_bytes(4));
    }

    #[\Override]
    protected function tearDown(): void
    {
        if ($this->slug === '' || !isset($this->control)) {
            return;
        }

        $dbName = 'wc_tenant_' . $this->slug;
        $dbUser = 'wc_' . $this->slug;

        try {
            $this->control->exec("DROP DATABASE IF EXISTS `{$dbName}`");
            $this->control->exec("DROP USER IF EXISTS `{$dbUser}`@`%`");
            $this->control->exec("DROP USER IF EXISTS `ltd_{$this->slug}`@`%`");
            $this->control->prepare('DELETE FROM tenants WHERE slug = :slug')->execute(['slug' => $this->slug]);
        } catch (\PDOException) {
            // Best effort: a failed provision may have left none of it behind.
        }
    }

    private function provisioner(): TenantProvisioner
    {
        return new TenantProvisioner(
            $this->repo,
            $this->dbManager,
            'mysql',
            new PasswordHasher(),
            new MySqlTenantDatabaseCreator($this->adminUrl),
        );
    }

    public function testProvisioningCreatesAWorkingTenantDatabase(): void
    {
        $result = $this->provisioner()->provision($this->slug, 'Integration Co', 'admin@' . $this->slug . '.test');

        self::assertTrue($result->success, 'provisioning failed: ' . $result->error);
        self::assertSame($this->slug, $result->slug);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $result->adminPassword);

        // The database is on the server, not just in the registry.
        $dbName = 'wc_tenant_' . $this->slug;
        $exists = $this->control
            ->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = :name');
        $exists->execute(['name' => $dbName]);
        self::assertSame($dbName, $exists->fetchColumn());

        // The tenant is registered and points at it.
        $tenant = $this->repo->findBySlug($this->slug);
        self::assertNotNull($tenant);
        self::assertSame($dbName, $tenant->dbName());
        self::assertSame('wc_' . $this->slug, $tenant->dbUser());
        // The host has to be the server the database was just created on. It
        // used to be the hardcoded Docker service name, which resolves in
        // compose and nowhere else -- CI caught it by failing to resolve.
        self::assertSame(parse_url($this->adminUrl, \PHP_URL_HOST), $tenant->dbHost());
        self::assertNotSame('', $tenant->dbPassword(), 'the login password is stored encrypted');

        // The schema was deployed into it.
        $tenantPdo = $this->dbManager->getConnection($tenant);
        $tables = $tenantPdo->query('SHOW TABLES')?->fetchAll(\PDO::FETCH_COLUMN);
        self::assertIsArray($tables);
        self::assertContains('webcal_user', $tables);
        self::assertContains('webcal_entry', $tables);

        // And the admin it reports can actually log in with the password it
        // handed back -- the pair that matters to whoever provisioned it.
        $row = $tenantPdo
            ->query("SELECT cal_passwd, cal_email, cal_is_admin FROM webcal_user WHERE cal_login = 'admin'")
            ?->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame('admin@' . $this->slug . '.test', $row['cal_email']);
        self::assertSame('Y', $row['cal_is_admin']);
        self::assertTrue((new PasswordHasher())->verify($result->adminPassword, (string) $row['cal_passwd']));
    }

    public function testTheTenantLoginReachesOnlyItsOwnDatabase(): void
    {
        // The point of a login per tenant: the grant covers one database, so a
        // tenant connection cannot read the control plane's registry.
        $result = $this->provisioner()->provision($this->slug, 'Integration Co', 'admin@' . $this->slug . '.test');
        self::assertTrue($result->success, 'provisioning failed: ' . $result->error);

        $tenant = $this->repo->findBySlug($this->slug);
        self::assertNotNull($tenant);
        $tenantPdo = $this->dbManager->getConnection($tenant);

        $this->expectException(\PDOException::class);
        $tenantPdo->query('SELECT COUNT(*) FROM webcalendar.tenants');
    }

    public function testProvisioningTheSameSlugTwiceIsRefusedWithoutTouchingTheFirst(): void
    {
        $first = $this->provisioner()->provision($this->slug, 'First', 'admin@' . $this->slug . '.test');
        self::assertTrue($first->success, 'provisioning failed: ' . $first->error);

        $second = $this->provisioner()->provision($this->slug, 'Second', 'other@' . $this->slug . '.test');

        self::assertFalse($second->success);
        self::assertSame("Tenant with slug '{$this->slug}' already exists.", $second->error);
        self::assertSame('First', $this->repo->findBySlug($this->slug)?->name());
    }

    public function testASecondAttemptAfterAFailedProvisionSucceeds(): void
    {
        // First attempt: the database and login are really created, then the
        // connection to them fails because the host is unreachable. That is
        // after save(), so it exercises the rollback.
        $broken = new TenantProvisioner(
            $this->repo,
            $this->dbManager,
            'mysql',
            new PasswordHasher(),
            new UnreachableHostCreator(new MySqlTenantDatabaseCreator($this->adminUrl)),
        );

        $failed = $broken->provision($this->slug, 'First Try', 'admin@' . $this->slug . '.test');
        self::assertFalse($failed->success);

        // The login exists on the server with the password that attempt made.
        $user = $this->control->prepare('SELECT User FROM mysql.user WHERE User = :user');
        $user->execute(['user' => 'wc_' . $this->slug]);
        self::assertSame('wc_' . $this->slug, $user->fetchColumn());

        // Nothing is registered, so the slug is free to try again.
        self::assertNull($this->repo->findBySlug($this->slug));

        // Second attempt, reaching the real host. This is where CREATE USER IF
        // NOT EXISTS used to leave the first attempt's password in place while
        // the registry stored the second one, making the slug unprovisionable
        // for good.
        $result = $this->provisioner()->provision($this->slug, 'Second Try', 'admin@' . $this->slug . '.test');

        self::assertTrue($result->success, 'the retry failed: ' . $result->error);

        $tenant = $this->repo->findBySlug($this->slug);
        self::assertNotNull($tenant);
        self::assertSame('Second Try', $tenant->name());

        // The stored password authenticates: the connection is made with it.
        $tenantPdo = $this->dbManager->getConnection($tenant);
        $tables = $tenantPdo->query('SHOW TABLES')?->fetchAll(\PDO::FETCH_COLUMN);
        self::assertIsArray($tables);
        self::assertContains('webcal_user', $tables);
    }

    public function testADdlRefusalIsReportedRatherThanPassedOverInSilence(): void
    {
        // A login that can connect but may not CREATE DATABASE. Without the
        // driver set to throw, exec() would just return false and provisioning
        // would carry on to register a tenant with no database behind it.
        $admin = (array) parse_url($this->adminUrl);
        $limited = 'ltd_' . $this->slug;
        $this->control->exec("CREATE USER `{$limited}`@`%` IDENTIFIED BY 'probe_secret'");
        $adminDb = ltrim((string) ($admin['path'] ?? ''), '/');
        $this->control->exec("GRANT SELECT ON `{$adminDb}`.* TO `{$limited}`@`%`");

        $limitedUrl = \sprintf(
            'mysql://%s:probe_secret@%s:%d%s',
            $limited,
            (string) $admin['host'],
            (int) ($admin['port'] ?? 3306),
            (string) ($admin['path'] ?? ''),
        );

        $creator = new MySqlTenantDatabaseCreator($limitedUrl);

        try {
            $creator->create('wc_tenant_' . $this->slug, 'wc_' . $this->slug, 'tenant_secret');
            self::fail('a refused CREATE DATABASE should not look like a successful creation');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString("Could not create tenant database 'wc_tenant_{$this->slug}'", $e->getMessage());
            self::assertInstanceOf(\PDOException::class, $e->getPrevious());
        }

        // And it really was not created.
        $exists = $this->control
            ->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = :name');
        $exists->execute(['name' => 'wc_tenant_' . $this->slug]);
        self::assertFalse($exists->fetchColumn());
    }
}

/** Creates the database for real, then reports a host nothing can reach. */
final readonly class UnreachableHostCreator implements TenantDatabaseCreator
{
    public function __construct(private TenantDatabaseCreator $inner) {}

    #[\Override]
    public function create(string $dbName, string $dbUser, #[\SensitiveParameter] string $dbPassword): void
    {
        $this->inner->create($dbName, $dbUser, $dbPassword);
    }

    #[\Override]
    public function serverHost(): string
    {
        return 'nonexistent.invalid';
    }
}
