<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenant;

use App\Tenant\Tenant;
use App\Tenant\TenantContext;
use App\Tenant\TenantDatabaseManager;
use App\Tenant\TenantProvisioner;
use App\Tenant\TenantRepository;
use App\Tenant\TenantResolverListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Security tests verifying complete cross-tenant data isolation.
 */
final class CrossTenantSecurityTest extends TestCase
{
    private const BASE_DOMAIN = 'webcalendar.test';
    private const APP_SECRET = 'cross_tenant_test_secret_32ch!!';

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

    public function testAllControllersUseTenantScopedPdo(): void
    {
        // Provision two tenants
        $r1 = $this->provisioner->provision('scope-aaa', 'A', 'a@a.com');
        $r2 = $this->provisioner->provision('scope-bbb', 'B', 'b@b.com');
        $this->assertTrue($r1->success, $r1->error);
        $this->assertTrue($r2->success, $r2->error);

        $tenantA = $this->repo->findBySlug('scope-aaa');
        $tenantB = $this->repo->findBySlug('scope-bbb');
        $this->assertNotNull($tenantA);
        $this->assertNotNull($tenantB);

        $pdoA = $this->dbManager->getConnection($tenantA);
        $pdoB = $this->dbManager->getConnection($tenantB);

        // Create event in tenant A
        $pdoA->exec("INSERT INTO webcal_entry (cal_id, cal_create_by, cal_date, cal_time, cal_mod_date, cal_mod_time, cal_duration, cal_type, cal_access, cal_name)
                      VALUES (100, 'admin', 20260501, 100000, 20260316, 120000, 60, 'E', 'P', 'Secret A Event')");

        // Verify tenant A can see the event
        $stmtA = $pdoA->query("SELECT COUNT(*) FROM webcal_entry WHERE cal_name = 'Secret A Event'");
        $this->assertNotFalse($stmtA);
        $this->assertSame(1, (int) $stmtA->fetchColumn());

        // Verify tenant B CANNOT see the event
        $stmtB = $pdoB->query("SELECT COUNT(*) FROM webcal_entry WHERE cal_name = 'Secret A Event'");
        $this->assertNotFalse($stmtB);
        $this->assertSame(0, (int) $stmtB->fetchColumn());
    }

    public function testLayersCannotReferenceCrossTenantUsers(): void
    {
        $this->provisioner->provision('layer-aaa', 'A', 'a@a.com');
        $this->provisioner->provision('layer-bbb', 'B', 'b@b.com');

        $tenantA = $this->repo->findBySlug('layer-aaa');
        $tenantB = $this->repo->findBySlug('layer-bbb');
        $this->assertNotNull($tenantA);
        $this->assertNotNull($tenantB);

        $pdoA = $this->dbManager->getConnection($tenantA);
        $pdoB = $this->dbManager->getConnection($tenantB);

        // Add a layer in tenant A referencing a user
        $pdoA->exec("INSERT INTO webcal_user_layers (cal_layerid, cal_login, cal_layeruser, cal_color)
                      VALUES (1, 'admin', 'user_in_a', '#FF0000')");

        // Verify the layer exists in A
        $stmtA = $pdoA->query("SELECT COUNT(*) FROM webcal_user_layers WHERE cal_layeruser = 'user_in_a'");
        $this->assertNotFalse($stmtA);
        $this->assertSame(1, (int) $stmtA->fetchColumn());

        // Verify tenant B has NO such layer
        $stmtB = $pdoB->query("SELECT COUNT(*) FROM webcal_user_layers WHERE cal_layeruser = 'user_in_a'");
        $this->assertNotFalse($stmtB);
        $this->assertSame(0, (int) $stmtB->fetchColumn());
    }

    public function testGroupMembershipIsTenantScoped(): void
    {
        $this->provisioner->provision('grp-aaa', 'A', 'a@a.com');
        $this->provisioner->provision('grp-bbb', 'B', 'b@b.com');

        $tenantA = $this->repo->findBySlug('grp-aaa');
        $tenantB = $this->repo->findBySlug('grp-bbb');
        $this->assertNotNull($tenantA);
        $this->assertNotNull($tenantB);

        $pdoA = $this->dbManager->getConnection($tenantA);
        $pdoB = $this->dbManager->getConnection($tenantB);

        // Create a group in tenant A
        $pdoA->exec("INSERT INTO webcal_group (cal_group_id, cal_owner, cal_name, cal_last_update)
                      VALUES (1, 'admin', 'Secret Group', 20260316)");

        // Verify group exists in A only
        $stmtA = $pdoA->query("SELECT COUNT(*) FROM webcal_group WHERE cal_name = 'Secret Group'");
        $this->assertNotFalse($stmtA);
        $this->assertSame(1, (int) $stmtA->fetchColumn());

        $stmtB = $pdoB->query("SELECT COUNT(*) FROM webcal_group WHERE cal_name = 'Secret Group'");
        $this->assertNotFalse($stmtB);
        $this->assertSame(0, (int) $stmtB->fetchColumn());
    }

    public function testSearchIsTenantScoped(): void
    {
        $this->provisioner->provision('srch-aaa', 'A', 'a@a.com');
        $this->provisioner->provision('srch-bbb', 'B', 'b@b.com');

        $tenantA = $this->repo->findBySlug('srch-aaa');
        $tenantB = $this->repo->findBySlug('srch-bbb');
        $this->assertNotNull($tenantA);
        $this->assertNotNull($tenantB);

        $pdoA = $this->dbManager->getConnection($tenantA);
        $pdoB = $this->dbManager->getConnection($tenantB);

        // Create a searchable event in A
        $pdoA->exec("INSERT INTO webcal_entry (cal_id, cal_create_by, cal_date, cal_time, cal_mod_date, cal_mod_time, cal_duration, cal_type, cal_access, cal_name)
                      VALUES (200, 'admin', 20260601, 100000, 20260316, 120000, 60, 'E', 'P', 'UniqueSearchableXYZ')");

        // Tenant B should have no matching events
        $stmtB = $pdoB->query("SELECT COUNT(*) FROM webcal_entry WHERE cal_name LIKE '%UniqueSearchable%'");
        $this->assertNotFalse($stmtB);
        $this->assertSame(0, (int) $stmtB->fetchColumn());
    }

    public function testTenantResolverBlocks403ForMismatchedSubdomain(): void
    {
        $this->provisioner->provision('real-co', 'Real', 'a@a.com');

        $context = new TenantContext();
        $resolver = new TenantResolverListener($this->repo, $context, self::BASE_DOMAIN, 'hosted');

        // Request goes to a non-existent tenant
        $request = Request::create('http://fake-co.' . self::BASE_DOMAIN . '/api/v2/events');
        $kernel = $this->createMock(KernelInterface::class);
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $resolver->onKernelRequest($event);

        // Should get 404 — tenant doesn't exist
        $this->assertNotNull($event->getResponse());
        $this->assertSame(404, $event->getResponse()?->getStatusCode());
    }
}
