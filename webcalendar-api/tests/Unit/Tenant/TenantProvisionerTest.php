<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenant;

use App\Tenant\TenantDatabaseManager;
use App\Tenant\TenantProvisioner;
use App\Tenant\TenantRepository;
use App\Tenant\TenantStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TenantProvisionerTest extends TestCase
{
    private const APP_SECRET = 'test_provisioner_secret_32chars!';

    private \PDO $controlPdo;
    private TenantRepository $repo;
    private TenantDatabaseManager $dbManager;
    private TenantProvisioner $provisioner;

    #[\Override]
    protected function setUp(): void
    {
        $this->controlPdo = new \PDO('sqlite::memory:');
        $this->controlPdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->controlPdo->exec(TenantRepository::SCHEMA_SQL);

        $this->repo = new TenantRepository($this->controlPdo);
        $this->dbManager = new TenantDatabaseManager(self::APP_SECRET);

        $this->provisioner = new TenantProvisioner(
            $this->repo,
            $this->dbManager,
            'sqlite', // Use SQLite for testing
        );
    }

    public function testProvisionCreatesTenantInRegistry(): void
    {
        $result = $this->provisioner->provision('newco', 'New Company', 'admin@newco.com');

        $this->assertTrue($result->success, 'Provisioning failed: ' . $result->error);
        $this->assertSame('newco', $result->slug);
        $this->assertNotEmpty($result->adminPassword);

        $tenant = $this->repo->findBySlug('newco');
        $this->assertNotNull($tenant);
        $this->assertSame('New Company', $tenant->name());
        $this->assertSame(TenantStatus::Active, $tenant->status());
    }

    public function testProvisionReturnsCredentials(): void
    {
        $result = $this->provisioner->provision('creds-co', 'Creds Co', 'admin@creds.com');

        $this->assertTrue($result->success);
        $this->assertSame('creds-co', $result->slug);
        $this->assertNotEmpty($result->adminPassword);
        $this->assertSame('admin@creds.com', $result->adminEmail);
    }

    public function testProvisionRejectsDuplicateSlug(): void
    {
        $this->provisioner->provision('dupe-co', 'Dupe', 'a@b.com');
        $result = $this->provisioner->provision('dupe-co', 'Dupe Again', 'c@d.com');

        $this->assertFalse($result->success);
        $this->assertNotEmpty($result->error);
    }

    public function testProvisionRejectsInvalidSlug(): void
    {
        $result = $this->provisioner->provision('INVALID', 'Bad', 'a@b.com');

        $this->assertFalse($result->success);
        $this->assertNotEmpty($result->error);
    }

    public function testProvisionRejectsReservedSlug(): void
    {
        $result = $this->provisioner->provision('admin', 'Admin', 'a@b.com');

        $this->assertFalse($result->success);
        $this->assertNotEmpty($result->error);
    }

    public function testProvisionGeneratesUniquePasswords(): void
    {
        $r1 = $this->provisioner->provision('pass-aaa', 'A', 'a@a.com');
        $r2 = $this->provisioner->provision('pass-bbb', 'B', 'b@b.com');

        $this->assertNotSame($r1->adminPassword, $r2->adminPassword);
    }

    // ------------------------------------- refused for the right reason

    private function tenantCount(): int
    {
        return (int) $this->controlPdo->query('SELECT COUNT(*) FROM tenants')?->fetchColumn();
    }

    /** @return iterable<string, array{string, string}> */
    public static function slugsTheTenantConstructorRefuses(): iterable
    {
        // Each of these is rejected by the Tenant constructor, and the
        // provisioner has to stop there. Every case below also fails a second
        // time further down -- the constructor is called again once the real
        // tenant is built -- so a failure alone proves nothing about where it
        // was caught. The message does: caught early it is the constructor's
        // own, caught late it arrives wrapped in "Provisioning failed:".
        yield 'too short' => ['ab', 'Tenant slug must be between 3 and 50 characters.'];
        yield 'upper case' => ['INVALID', 'Tenant slug must contain only lowercase letters, numbers, and hyphens.'];
        yield 'underscored' => ['not_a_slug', 'Tenant slug must contain only lowercase letters, numbers, and hyphens.'];
        yield 'reserved' => ['admin', "Tenant slug 'admin' is reserved."];
    }

    #[DataProvider('slugsTheTenantConstructorRefuses')]
    public function testABadSlugIsRefusedWhereItIsFirstSeen(string $slug, string $expected): void
    {
        $result = $this->provisioner->provision($slug, 'Bad', 'a@b.com');

        self::assertFalse($result->success);
        self::assertSame($expected, $result->error);
        self::assertSame(0, $this->tenantCount(), 'nothing is registered for a slug that was refused');
    }

    public function testADuplicateSlugIsRefusedByNameRatherThanByTheDatabase(): void
    {
        // tenants.slug is UNIQUE, so a second insert fails either way -- which
        // is why asserting "not successful" said nothing. The difference is
        // whether the operator is told the slug is taken or handed a wrapped
        // SQL constraint violation, and whether the second attempt got as far
        // as building a database at all.
        $first = $this->provisioner->provision('dupe-co', 'Dupe', 'a@b.com');
        self::assertTrue($first->success, $first->error);

        $second = $this->provisioner->provision('dupe-co', 'Dupe Again', 'c@d.com');

        self::assertFalse($second->success);
        self::assertSame("Tenant with slug 'dupe-co' already exists.", $second->error);
        self::assertSame(1, $this->tenantCount());
        self::assertSame('Dupe', $this->repo->findBySlug('dupe-co')?->name(), 'the first one is untouched');
    }

    public function testAnUnknownPlanIsRejectedAndSaysWhatIsValid(): void
    {
        $result = $this->provisioner->provision('plan-co', 'Plan', 'a@b.com', 'platinum');

        self::assertFalse($result->success);
        self::assertSame("Invalid tenant plan 'platinum'. Valid: free, pro, enterprise", $result->error);
        self::assertSame(0, $this->tenantCount());
    }

    // ------------------------------------------------ the admin's first login

    public function testTheGeneratedAdminPasswordCarriesItsFullEntropy(): void
    {
        // This is handed to a human as their way in, so its length is part of
        // the contract rather than an implementation detail: 16 random bytes
        // rendered as hex.
        $result = $this->provisioner->provision('entropy-co', 'Entropy', 'a@b.com');

        self::assertTrue($result->success, $result->error);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $result->adminPassword);
    }

    public function testTheAdminUserCanLogInWithThePasswordItWasGiven(): void
    {
        // The password comes back in the result and is hashed into the tenant
        // database; if those two ever diverge the operator is locked out of
        // the tenant they just created.
        $result = $this->provisioner->provision('login-co', 'Login', 'admin@login-co.com');
        self::assertTrue($result->success, $result->error);

        $tenant = $this->repo->findBySlug('login-co');
        self::assertNotNull($tenant);
        $row = $this->dbManager->getConnection($tenant)
            ->query("SELECT cal_passwd, cal_email, cal_is_admin, cal_enabled FROM webcal_user WHERE cal_login = 'admin'")
            ?->fetch(\PDO::FETCH_ASSOC);

        self::assertIsArray($row);
        self::assertSame('admin@login-co.com', $row['cal_email']);
        self::assertSame('Y', $row['cal_is_admin']);
        self::assertSame('Y', $row['cal_enabled']);
        self::assertTrue(
            (new \App\Security\PasswordHasher())->verify($result->adminPassword, (string) $row['cal_passwd']),
        );
    }

    // ------------------------------------------ what is not implemented yet

    /** @return iterable<string, array{string}> */
    public static function driversWithoutDatabaseCreation(): iterable
    {
        // Both non-SQLite drivers take the same branch, and its first
        // statement is createMySqlDatabase(), which throws unconditionally.
        yield 'mysql' => ['mysql'];
        yield 'pgsql' => ['pgsql'];
    }

    #[DataProvider('driversWithoutDatabaseCreation')]
    public function testProvisioningCannotYetSucceedOutsideSqlite(string $driver): void
    {
        // Recorded rather than worked around: every test above runs against
        // the SQLite driver, which is the one branch that is finished, so
        // nothing in this file said that the driver the constructor defaults
        // to -- and the one hosted mode actually uses -- always fails.
        //
        // It also explains why $dbName and $dbUser are unassertable: they are
        // built on every provision, but the only code that consumes them is
        // this branch, and it throws before they are read. When database
        // creation lands, those two lines become testable and this case is
        // the one that should start failing.
        $provisioner = new TenantProvisioner($this->repo, $this->dbManager, $driver);

        $result = $provisioner->provision('driver-co', 'Driver Co', 'a@b.com');

        self::assertFalse($result->success);
        self::assertSame(
            'Provisioning failed: MySQL database creation requires root PDO (not yet implemented for tests)',
            $result->error,
            'anything thrown mid-provision reaches the operator with the prefix that says where it came from',
        );
        self::assertSame(0, $this->tenantCount(), 'a failed provision leaves no half-built tenant behind');
    }
}
