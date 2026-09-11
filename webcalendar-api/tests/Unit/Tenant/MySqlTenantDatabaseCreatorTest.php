<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenant;

use App\Tenant\MySqlTenantDatabaseCreator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The checks that happen before any server is dialled.
 *
 * Database and user names are interpolated into DDL, which takes no bound
 * parameters, so they are validated rather than trusted. Everything here runs
 * without a MySQL connection; the DDL itself is covered by
 * tests/Integration/TenantProvisioningOnMySqlTest.php.
 */
final class MySqlTenantDatabaseCreatorTest extends TestCase
{
    private const ADMIN_URL = 'mysql://root:secret@mysql:3306/webcalendar';

    /** @return iterable<string, array{string}> */
    public static function unusableIdentifiers(): iterable
    {
        // The names arrive built from a validated slug, so none of these
        // should be reachable -- which is the point: if one ever is, it is
        // refused here rather than concatenated into a CREATE DATABASE.
        yield 'a back-quote' => ['wc_tenant_`x`'];
        yield 'a quote' => ["wc_tenant_'x"];
        yield 'a semicolon' => ['wc_tenant_x; DROP DATABASE webcalendar'];
        yield 'a space' => ['wc tenant'];
        yield 'a hyphen' => ['wc-tenant-acme'];
        yield 'a backslash' => ['wc_tenant_\\x'];
        yield 'over sixty-four characters' => ['wc_tenant_' . str_repeat('a', 60)];
    }

    #[DataProvider('unusableIdentifiers')]
    public function testAnUnusableDatabaseNameIsRefusedBeforeConnecting(string $dbName): void
    {
        $creator = new MySqlTenantDatabaseCreator(self::ADMIN_URL);

        // The message matters, not just the class: this URL points at a host
        // that is not there, so a name which slipped past validation would
        // still raise -- just from the connection instead of the check.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid database name');
        $creator->create($dbName, 'wc_acme', 'password');
    }

    #[DataProvider('unusableIdentifiers')]
    public function testAnUnusableUserNameIsRefusedBeforeConnecting(string $dbUser): void
    {
        $creator = new MySqlTenantDatabaseCreator(self::ADMIN_URL);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid database user');
        $creator->create('wc_tenant_acme', $dbUser, 'password');
    }

    public function testAnIdentifierOfExactlyTheMaximumLengthIsAccepted(): void
    {
        // MySQL allows 64 characters, so 64 is usable and 65 is not. Nothing
        // is listening on port 9, so getting as far as a connection error is
        // how a name proves it passed validation.
        $creator = new MySqlTenantDatabaseCreator('mysql://root:secret@127.0.0.1:9/webcalendar');
        $sixtyFour = 'wc_tenant_' . str_repeat('a', 54);
        self::assertSame(64, \strlen($sixtyFour));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not create tenant database');
        $creator->create($sixtyFour, 'wc_acme', 'password');
    }

    public function testAnIdentifierOneCharacterTooLongIsRefused(): void
    {
        $creator = new MySqlTenantDatabaseCreator('mysql://root:secret@127.0.0.1:9/webcalendar');
        $sixtyFive = 'wc_tenant_' . str_repeat('a', 55);
        self::assertSame(65, \strlen($sixtyFive));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must be 1 to 64 characters');
        $creator->create($sixtyFive, 'wc_acme', 'password');
    }

    public function testAnEmptyIdentifierIsRefusedForItsLengthToo(): void
    {
        // The empty case and the too-long case share a branch; without the
        // first half of that test an empty name falls through to the
        // character check and is reported as the wrong problem.
        $creator = new MySqlTenantDatabaseCreator('mysql://root:secret@127.0.0.1:9/webcalendar');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must be 1 to 64 characters');
        $creator->create('', 'wc_acme', 'password');
    }

    public function testTheRefusalNamesWhatWasWrong(): void
    {
        $creator = new MySqlTenantDatabaseCreator(self::ADMIN_URL);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid database name');
        $creator->create('wc-tenant-acme', 'wc_acme', 'password');
    }

    public function testAnUnconfiguredAdminUrlSaysSoRatherThanFailingLater(): void
    {
        // The common case for a standalone deployment: no admin credentials,
        // and provisioning should say that plainly rather than reporting
        // whatever a connection attempt happens to produce.
        $creator = new MySqlTenantDatabaseCreator('');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('set TENANT_ADMIN_DATABASE_URL');
        $creator->create('wc_tenant_acme', 'wc_acme', 'password');
    }

    public function testNamesAreCheckedBeforeTheAdminUrlIsEvenLookedAt(): void
    {
        // Order matters: a malformed name must not reach the point where an
        // unconfigured URL is the error being reported.
        $creator = new MySqlTenantDatabaseCreator('');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid database name');
        $creator->create('wc-tenant-acme', 'wc_acme', 'password');
    }

    public function testAValidNameGetsPastTheChecksAndTriesToConnect(): void
    {
        // Nothing is listening on port 9, so this proves the names were
        // accepted and the failure is the connection rather than validation.
        // The driver's own exception is kept underneath: it is the only thing
        // that says whether the admin login was refused, the host was wrong,
        // or the server was simply down.
        $creator = new MySqlTenantDatabaseCreator('mysql://root:secret@127.0.0.1:9/webcalendar');

        try {
            $creator->create('wc_tenant_acme', 'wc_acme', 'password');
            self::fail('an unreachable server should not look like a successful creation');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString("Could not create tenant database 'wc_tenant_acme'", $e->getMessage());
            self::assertSame(0, $e->getCode());
            self::assertInstanceOf(\PDOException::class, $e->getPrevious());
        }
    }

    public function testTheServerHostIsTheOneTheAdminUrlNames(): void
    {
        // The tenant row stores this as its host, so it has to be the server
        // the database was actually created on and not a fixed name.
        $creator = new MySqlTenantDatabaseCreator('mysql://root:secret@db.example.test:3306/webcalendar');

        self::assertSame('db.example.test', $creator->serverHost());
    }

    public function testAnAdminUrlWithNoHostFallsBackToTheComposeServiceName(): void
    {
        // TenantProvisioner constructs an unconfigured creator by default, so
        // this path is reached whenever provisioning is not set up.
        self::assertSame('mysql', (new MySqlTenantDatabaseCreator(''))->serverHost());
        self::assertSame('mysql', (new MySqlTenantDatabaseCreator('not a url'))->serverHost());
    }
}
