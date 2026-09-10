<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenant;

use App\Tenant\Tenant;
use App\Tenant\TenantDatabaseManager;
use App\Tenant\TenantPlan;
use App\Tenant\TenantStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TenantDatabaseManagerTest extends TestCase
{
    private const APP_SECRET = 'test_secret_key_32_chars_long!!!';

    public function testEncryptAndDecryptRoundTrip(): void
    {
        $manager = new TenantDatabaseManager(self::APP_SECRET);

        $plaintext = 'my_db_password_123';
        $encrypted = $manager->encryptPassword($plaintext);

        $this->assertNotSame($plaintext, $encrypted);
        $this->assertSame($plaintext, $manager->decryptPassword($encrypted));
    }

    public function testDifferentPlaintextsProduceDifferentCiphertexts(): void
    {
        $manager = new TenantDatabaseManager(self::APP_SECRET);

        $enc1 = $manager->encryptPassword('password1');
        $enc2 = $manager->encryptPassword('password2');

        $this->assertNotSame($enc1, $enc2);
    }

    public function testDecryptWithWrongKeyFails(): void
    {
        $manager1 = new TenantDatabaseManager(self::APP_SECRET);
        $manager2 = new TenantDatabaseManager('different_secret_key_32_chars!!');

        $encrypted = $manager1->encryptPassword('secret');

        $this->expectException(\RuntimeException::class);
        $manager2->decryptPassword($encrypted);
    }

    public function testGetConnectionReturnsPdo(): void
    {
        $manager = new TenantDatabaseManager(self::APP_SECRET);

        // Use SQLite in-memory for testing
        $tenant = new Tenant(
            id: 1,
            slug: 'test-tenant',
            name: 'Test',
            dbHost: '',
            dbName: ':memory:',
            dbUser: '',
            dbPassword: '',
            plan: TenantPlan::Free,
            status: TenantStatus::Active,
        );

        $pdo = $manager->getConnection($tenant);
        $this->assertInstanceOf(\PDO::class, $pdo);
    }

    public function testConnectionIsCachedPerTenant(): void
    {
        $manager = new TenantDatabaseManager(self::APP_SECRET);

        $tenant = new Tenant(
            id: 1,
            slug: 'cached-tenant',
            name: 'Test',
            dbHost: '',
            dbName: ':memory:',
            dbUser: '',
            dbPassword: '',
            plan: TenantPlan::Free,
            status: TenantStatus::Active,
        );

        $pdo1 = $manager->getConnection($tenant);
        $pdo2 = $manager->getConnection($tenant);

        $this->assertSame($pdo1, $pdo2);
    }

    public function testDifferentTenantsGetDifferentConnections(): void
    {
        $manager = new TenantDatabaseManager(self::APP_SECRET);

        $tenantA = new Tenant(1, 'tenant-aaa', 'A', '', ':memory:', '', '', TenantPlan::Free, TenantStatus::Active);
        $tenantB = new Tenant(2, 'tenant-bbb', 'B', '', ':memory:', '', '', TenantPlan::Free, TenantStatus::Active);

        $pdoA = $manager->getConnection($tenantA);
        $pdoB = $manager->getConnection($tenantB);

        // SQLite :memory: creates a new DB each time, so they're different objects
        $this->assertNotSame($pdoA, $pdoB);
    }

    public function testGetConnectionThrowsForUnreachableDb(): void
    {
        $manager = new TenantDatabaseManager(self::APP_SECRET);

        $tenant = new Tenant(
            id: 1,
            slug: 'bad-tenant',
            name: 'Bad',
            dbHost: 'nonexistent-host-999.invalid',
            dbName: 'no_such_db',
            dbUser: 'nobody',
            dbPassword: $manager->encryptPassword('nope'),
            plan: TenantPlan::Free,
            status: TenantStatus::Active,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to connect');
        $manager->getConnection($tenant);
    }

    // ------------------------------------------ ciphertext that is too small

    private static function tenantWith(string $dbHost, string $dbName): Tenant
    {
        return new Tenant(1, 'acme', 'Acme', $dbHost, $dbName, 'dbuser', '', TenantPlan::Pro, TenantStatus::Active);
    }

    private static function ivPlusTagLength(): int
    {
        $ivLength = openssl_cipher_iv_length('aes-256-gcm');
        self::assertIsInt($ivLength);

        return $ivLength + 16; // the GCM tag is always 16 bytes
    }

    public function testCiphertextShorterThanAnIvAndTagSaysSo(): void
    {
        // The length guard exists to stop substr() slicing an iv and a tag out
        // of data that does not contain them. Only the round trip and the
        // wrong-key case were covered, and both of those end in "Decryption
        // failed" -- so the guard could be removed, or its minimum computed by
        // subtracting the tag length instead of adding it, and the caller
        // would still see an exception, just a misleading one.
        $manager = new TenantDatabaseManager(self::APP_SECRET);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Encrypted data too short');
        $manager->decryptPassword(base64_encode(str_repeat("\x00", self::ivPlusTagLength() - 1)));
    }

    public function testCiphertextOfExactlyAnIvAndTagIsLongEnoughToTry(): void
    {
        // One byte more than the previous case: the guard is `<`, so this is
        // long enough to attempt, and it fails at decryption instead. With
        // `<=` the shortest real payload would be rejected out of hand.
        $manager = new TenantDatabaseManager(self::APP_SECRET);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Decryption failed');
        $manager->decryptPassword(base64_encode(str_repeat("\x00", self::ivPlusTagLength())));
    }

    public function testSomethingThatIsNotBase64AtAllIsRejectedFirst(): void
    {
        $manager = new TenantDatabaseManager(self::APP_SECRET);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to decode encrypted password');
        $manager->decryptPassword('not!valid!base64!');
    }

    // -------------------------------------- which tenants get a sqlite handle

    /** @return iterable<string, array{string, string}> */
    public static function tenantsThatAreNotSqlite(): iterable
    {
        // The sqlite branch needs an empty host *and* a recognised database
        // name. Loosen either half of that and a real MySQL tenant is handed a
        // sqlite handle instead -- silently, against a file named after its
        // database, with none of its data in it.
        yield 'a host with a memory database name' => ['mysql.internal', ':memory:'];
        yield 'a host with a sqlite file name' => ['mysql.internal', 'tenant.sqlite'];
        yield 'no host but a mysql database name' => ['', 'wc_tenant_acme'];
    }

    #[DataProvider('tenantsThatAreNotSqlite')]
    public function testATenantThatIsNotSqliteIsNotGivenASqliteHandle(string $dbHost, string $dbName): void
    {
        $manager = new TenantDatabaseManager(self::APP_SECRET);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Unable to connect to tenant 'acme' database");
        $manager->getConnection(self::tenantWith($dbHost, $dbName));
    }

    public function testAnEmptyHostWithAMemoryDatabaseIsSqlite(): void
    {
        $pdo = (new TenantDatabaseManager(self::APP_SECRET))->getConnection(self::tenantWith('', ':memory:'));

        self::assertSame('sqlite', $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
    }

    public function testTheSqliteHandleRaisesRatherThanReturningFalse(): void
    {
        // Without ERRMODE_EXCEPTION a failing statement returns false and the
        // caller carries on with it. Every repository in this codebase assumes
        // it will hear about a broken query.
        $pdo = (new TenantDatabaseManager(self::APP_SECRET))->getConnection(self::tenantWith('', ':memory:'));

        self::assertSame(\PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(\PDO::ATTR_ERRMODE));

        $this->expectException(\PDOException::class);
        $pdo->query('SELECT * FROM a_table_that_does_not_exist');
    }

    public function testAConnectionFailureKeepsTheDriverErrorItWraps(): void
    {
        // The RuntimeException is what callers see, but the PDOException
        // underneath is the only thing that says *why* -- host unknown,
        // access denied, database missing. Dropping the chaining, or giving
        // the wrapper a code of its own, loses that on the way out.
        $manager = new TenantDatabaseManager(self::APP_SECRET);

        try {
            $manager->getConnection(self::tenantWith('mysql.internal', 'wc_tenant_acme'));
            self::fail('an unreachable host should not yield a connection');
        } catch (\RuntimeException $e) {
            self::assertSame(0, $e->getCode());
            self::assertInstanceOf(\PDOException::class, $e->getPrevious());
        }
    }
}
