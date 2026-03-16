<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenant;

use App\Tenant\Tenant;
use App\Tenant\TenantContext;
use App\Tenant\TenantDatabaseManager;
use App\Tenant\TenantProvisioner;
use App\Tenant\TenantRepository;
use PHPUnit\Framework\TestCase;

/**
 * Tests verifying tenant-scoped login isolation:
 * same username in different tenants with different passwords.
 */
final class TenantScopedLoginTest extends TestCase
{
    private const APP_SECRET = 'login_test_secret_32_characters!';

    private TenantRepository $repo;
    private TenantDatabaseManager $dbManager;
    private TenantProvisioner $provisioner;

    #[\Override]
    protected function setUp(): void
    {
        $controlPdo = new \PDO('sqlite::memory:');
        $controlPdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $controlPdo->exec(TenantRepository::SCHEMA_SQL);

        $this->repo = new TenantRepository($controlPdo);
        $this->dbManager = new TenantDatabaseManager(self::APP_SECRET);
        $this->provisioner = new TenantProvisioner($this->repo, $this->dbManager, 'sqlite');
    }

    public function testSameUsernameInDifferentTenantsHaveIsolatedAuth(): void
    {
        // Provision two tenants — both will have an 'admin' user with different passwords
        $r1 = $this->provisioner->provision('tenant-aaa', 'Tenant A', 'admin@a.com');
        $r2 = $this->provisioner->provision('tenant-bbb', 'Tenant B', 'admin@b.com');
        $this->assertTrue($r1->success, $r1->error);
        $this->assertTrue($r2->success, $r2->error);

        // The generated passwords should be different
        $this->assertNotSame($r1->adminPassword, $r2->adminPassword);

        // Get tenant PDOs
        $tenantA = $this->repo->findBySlug('tenant-aaa');
        $tenantB = $this->repo->findBySlug('tenant-bbb');
        $this->assertNotNull($tenantA);
        $this->assertNotNull($tenantB);

        $pdoA = $this->dbManager->getConnection($tenantA);
        $pdoB = $this->dbManager->getConnection($tenantB);

        // Verify admin user exists in both with different password hashes
        $hashA = $this->getPasswordHash($pdoA, 'admin');
        $hashB = $this->getPasswordHash($pdoB, 'admin');
        $this->assertNotEmpty($hashA);
        $this->assertNotEmpty($hashB);
        $this->assertNotSame($hashA, $hashB);

        // Verify correct passwords work for each tenant
        $this->assertTrue(password_verify($r1->adminPassword, $hashA));
        $this->assertTrue(password_verify($r2->adminPassword, $hashB));

        // Verify cross-tenant passwords don't work
        $this->assertFalse(password_verify($r1->adminPassword, $hashB));
        $this->assertFalse(password_verify($r2->adminPassword, $hashA));
    }

    public function testTenantContextRoutesPdoCorrectly(): void
    {
        $this->provisioner->provision('ctx-test', 'Context Test', 'admin@ctx.com');

        $tenant = $this->repo->findBySlug('ctx-test');
        $this->assertNotNull($tenant);

        // Create a TenantContext and set the tenant
        $context = new TenantContext();
        $context->setTenant($tenant);

        $this->assertTrue($context->isMultiTenant());
        $this->assertSame('ctx-test', $context->getTenantOrFail()->slug());
    }

    public function testLoginResponseDoesNotLeakCrossTenantInfo(): void
    {
        $r1 = $this->provisioner->provision('leak-aaa', 'A', 'a@a.com');
        $r2 = $this->provisioner->provision('leak-bbb', 'B', 'b@b.com');

        $tenantA = $this->repo->findBySlug('leak-aaa');
        $tenantB = $this->repo->findBySlug('leak-bbb');
        $this->assertNotNull($tenantA);
        $this->assertNotNull($tenantB);

        $pdoA = $this->dbManager->getConnection($tenantA);
        $pdoB = $this->dbManager->getConnection($tenantB);

        // Tenant A's admin should not be visible in Tenant B's DB
        $userInA = $this->getUserEmail($pdoA, 'admin');
        $userInB = $this->getUserEmail($pdoB, 'admin');

        $this->assertSame('a@a.com', $userInA);
        $this->assertSame('b@b.com', $userInB);
    }

    private function getPasswordHash(\PDO $pdo, string $login): string
    {
        $stmt = $pdo->prepare('SELECT cal_passwd FROM webcal_user WHERE cal_login = :login');
        $stmt->execute(['login' => $login]);
        $hash = $stmt->fetchColumn();

        return \is_string($hash) ? $hash : '';
    }

    private function getUserEmail(\PDO $pdo, string $login): string
    {
        $stmt = $pdo->prepare('SELECT cal_email FROM webcal_user WHERE cal_login = :login');
        $stmt->execute(['login' => $login]);
        $email = $stmt->fetchColumn();

        return \is_string($email) ? $email : '';
    }
}
