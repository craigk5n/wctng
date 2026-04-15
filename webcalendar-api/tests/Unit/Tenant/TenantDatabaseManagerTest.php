<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenant;

use App\Tenant\Tenant;
use App\Tenant\TenantDatabaseManager;
use App\Tenant\TenantPlan;
use App\Tenant\TenantStatus;
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
}
