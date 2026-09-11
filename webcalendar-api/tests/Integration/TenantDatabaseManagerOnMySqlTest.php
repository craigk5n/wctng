<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\DatabaseDsn;
use App\Tenant\MySqlTenantDatabaseCreator;
use App\Tenant\Tenant;
use App\Tenant\TenantDatabaseManager;
use App\Tenant\TenantPlan;
use App\Tenant\TenantStatus;
use PHPUnit\Framework\TestCase;

/**
 * What a tenant connection is actually configured with, and which stored
 * password it uses.
 *
 * The unit suite can only watch this class fail to connect: every decision in
 * createConnection() past the SQLite branch -- decrypting the password, and the
 * three PDO options -- takes a server that answers. Mutating any of them
 * survives the unit suite for that reason, so they are pinned here instead.
 *
 * Skipped unless TENANT_ADMIN_DATABASE_URL names a login that may CREATE
 * DATABASE and CREATE USER.
 */
final class TenantDatabaseManagerOnMySqlTest extends TestCase
{
    private const APP_SECRET = 'tenant_db_manager_secret_32ch!!!';

    private string $adminUrl = '';
    private \PDO $control;
    private string $slug = '';
    private string $password = '';

    #[\Override]
    protected function setUp(): void
    {
        $url = getenv('TENANT_ADMIN_DATABASE_URL');
        if (!\is_string($url) || $url === '') {
            self::markTestSkipped('TENANT_ADMIN_DATABASE_URL is not set; no administrative MySQL login to connect with.');
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

        $this->slug = 'dm' . bin2hex(random_bytes(4));
        $this->password = bin2hex(random_bytes(12));

        // A real database and login for the connection to land in.
        (new MySqlTenantDatabaseCreator($this->adminUrl))
            ->create('wc_tenant_' . $this->slug, 'wc_' . $this->slug, $this->password);
    }

    #[\Override]
    protected function tearDown(): void
    {
        if ($this->slug === '' || !isset($this->control)) {
            return;
        }

        try {
            $this->control->exec('DROP DATABASE IF EXISTS `wc_tenant_' . $this->slug . '`');
            $this->control->exec('DROP USER IF EXISTS `wc_' . $this->slug . '`@`%`');
        } catch (\PDOException) {
            // Best effort.
        }
    }

    private function tenant(string $storedPassword): Tenant
    {
        return new Tenant(
            id: 1,
            slug: $this->slug,
            name: 'Manager Co',
            dbHost: (string) parse_url($this->adminUrl, \PHP_URL_HOST),
            dbName: 'wc_tenant_' . $this->slug,
            dbUser: 'wc_' . $this->slug,
            dbPassword: $storedPassword,
            plan: TenantPlan::Free,
            status: TenantStatus::Active,
        );
    }

    public function testAnEncryptedPasswordIsDecryptedToConnect(): void
    {
        // The registry stores ciphertext, so the connection only happens if
        // createConnection() decrypts it on the way past.
        $manager = new TenantDatabaseManager(self::APP_SECRET);
        $pdo = $manager->getConnection($this->tenant($manager->encryptPassword($this->password)));

        self::assertSame(1, $pdo->query('SELECT 1')?->fetchColumn());
    }

    public function testTheConnectionRaisesOnErrorInsteadOfReturningFalse(): void
    {
        $manager = new TenantDatabaseManager(self::APP_SECRET);
        $pdo = $manager->getConnection($this->tenant($manager->encryptPassword($this->password)));

        self::assertSame(\PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(\PDO::ATTR_ERRMODE));

        $this->expectException(\PDOException::class);
        $pdo->exec('NOT SQL AT ALL');
    }

    public function testRowsComeBackKeyedByNameOnly(): void
    {
        // Every caller reads rows by column name. With the default fetch mode
        // dropped, each row would also carry numeric keys -- which silently
        // doubles the size of anything iterated or serialised.
        $manager = new TenantDatabaseManager(self::APP_SECRET);
        $pdo = $manager->getConnection($this->tenant($manager->encryptPassword($this->password)));

        $row = $pdo->query('SELECT 1 AS one, 2 AS two')?->fetch();

        self::assertSame(['one', 'two'], array_keys((array) $row));
    }

    public function testStatementsArePreparedByTheServerRatherThanEmulated(): void
    {
        // Emulated prepares interpolate values into the SQL client-side, which
        // is the thing parameter binding is there to avoid.
        $manager = new TenantDatabaseManager(self::APP_SECRET);
        $pdo = $manager->getConnection($this->tenant($manager->encryptPassword($this->password)));

        self::assertFalse((bool) $pdo->getAttribute(\PDO::ATTR_EMULATE_PREPARES));
    }

    public function testAPasswordStoredInPlaintextStillConnects(): void
    {
        // The documented migration path: a registry row that was never
        // encrypted is used as it stands.
        $manager = new TenantDatabaseManager(self::APP_SECRET);
        $pdo = $manager->getConnection($this->tenant($this->password));

        self::assertSame(1, $pdo->query('SELECT 1')?->fetchColumn());
    }

    public function testAPasswordEncryptedUnderAnotherSecretIsRefusedByTheServer(): void
    {
        // Pinned as it stands, and it is worth knowing: when decryption fails
        // createConnection() assumes the value is plaintext and hands the
        // ciphertext to MySQL as the password. Rotating APP_SECRET therefore
        // fails every tenant with "Access denied" and nothing anywhere says
        // the secret is the reason.
        $stored = (new TenantDatabaseManager('a_completely_different_secret!!!'))
            ->encryptPassword($this->password);

        try {
            (new TenantDatabaseManager(self::APP_SECRET))->getConnection($this->tenant($stored));
            self::fail('the server should not have accepted the ciphertext as a password');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Access denied', $e->getMessage());
            self::assertStringNotContainsString('ecrypt', $e->getMessage(), 'nothing points at the real cause');
        }
    }
}
