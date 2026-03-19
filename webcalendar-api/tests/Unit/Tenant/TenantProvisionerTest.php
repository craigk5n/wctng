<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenant;

use App\Tenant\TenantDatabaseManager;
use App\Tenant\TenantProvisioner;
use App\Tenant\TenantRepository;
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
        $this->assertSame('active', $tenant->status());
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
}
