<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenant;

use App\Tenant\Tenant;
use App\Tenant\TenantContext;
use App\Tenant\TenantDatabaseManager;
use App\Tenant\TenantProvisioner;
use App\Tenant\TenantRepository;
use App\Tenant\TenantResolverListener;
use App\Tenant\TenantStatus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Full lifecycle E2E tests for tenant provisioning:
 * provision → resolve → use → suspend → delete
 */
final class TenantProvisioningE2ETest extends TestCase
{
    private const BASE_DOMAIN = 'webcalendar.test';
    private const APP_SECRET = 'e2e_test_secret_key_32_chars!!!';

    private \PDO $controlPdo;
    private TenantRepository $repo;
    private TenantDatabaseManager $dbManager;
    private TenantProvisioner $provisioner;
    private TenantContext $context;
    private TenantResolverListener $resolver;

    #[\Override]
    protected function setUp(): void
    {
        $this->controlPdo = new \PDO('sqlite::memory:');
        $this->controlPdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->controlPdo->exec(TenantRepository::SCHEMA_SQL);

        $this->repo = new TenantRepository($this->controlPdo);
        $this->dbManager = new TenantDatabaseManager(self::APP_SECRET);
        $this->provisioner = new TenantProvisioner($this->repo, $this->dbManager, 'sqlite');
        $this->context = new TenantContext();
        $this->resolver = new TenantResolverListener($this->repo, $this->context, self::BASE_DOMAIN, 'hosted');
    }

    private function createRequestEvent(string $host): RequestEvent
    {
        $request = Request::create('http://' . $host . '/api/v2/events');
        $kernel = $this->createMock(KernelInterface::class);
        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    public function testFullLifecycleProvisionLoginCreateEvent(): void
    {
        // 1. Provision tenant
        $result = $this->provisioner->provision('lifecycle', 'Lifecycle Corp', 'admin@lifecycle.com');
        $this->assertTrue($result->success, 'Provision failed: ' . $result->error);
        $this->assertNotEmpty($result->adminPassword);

        // 2. Resolve tenant via subdomain
        $event = $this->createRequestEvent('lifecycle.' . self::BASE_DOMAIN);
        $this->resolver->onKernelRequest($event);
        $this->assertNull($event->getResponse(), 'Resolver returned error response');

        $tenant = $this->context->getTenant();
        $this->assertNotNull($tenant);
        $this->assertSame('lifecycle', $tenant->slug());

        // 3. Connect to tenant DB and verify admin user exists
        $pdo = $this->dbManager->getConnection($tenant);
        $stmt = $pdo->prepare("SELECT cal_login, cal_email FROM webcal_user WHERE cal_login = 'admin'");
        $stmt->execute();
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($user);
        $this->assertSame('admin@lifecycle.com', $user['cal_email']);

        // 4. Create an event in tenant DB
        $pdo->exec("INSERT INTO webcal_entry (cal_id, cal_create_by, cal_date, cal_time, cal_mod_date, cal_mod_time, cal_duration, cal_type, cal_access, cal_name)
                     VALUES (1, 'admin', 20260401, 100000, 20260316, 120000, 60, 'E', 'P', 'Tenant Event')");

        $stmt = $pdo->query('SELECT cal_name FROM webcal_entry WHERE cal_id = 1');
        $this->assertNotFalse($stmt);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertSame('Tenant Event', $row['cal_name']);
    }

    public function testTwoTenantsHaveIsolatedData(): void
    {
        // Provision two tenants
        $r1 = $this->provisioner->provision('iso-alpha', 'Alpha', 'a@alpha.com');
        $r2 = $this->provisioner->provision('iso-beta', 'Beta', 'b@beta.com');
        $this->assertTrue($r1->success, $r1->error);
        $this->assertTrue($r2->success, $r2->error);

        // Get tenant PDOs
        $tenantA = $this->repo->findBySlug('iso-alpha');
        $tenantB = $this->repo->findBySlug('iso-beta');
        $this->assertNotNull($tenantA);
        $this->assertNotNull($tenantB);

        $pdoA = $this->dbManager->getConnection($tenantA);
        $pdoB = $this->dbManager->getConnection($tenantB);

        // Create event in tenant A only
        $pdoA->exec("INSERT INTO webcal_entry (cal_id, cal_create_by, cal_date, cal_time, cal_mod_date, cal_mod_time, cal_duration, cal_type, cal_access, cal_name)
                      VALUES (1, 'admin', 20260401, 100000, 20260316, 120000, 60, 'E', 'P', 'Alpha Only Event')");

        // Verify event exists in A
        $stmtA = $pdoA->query("SELECT COUNT(*) FROM webcal_entry WHERE cal_name = 'Alpha Only Event'");
        $this->assertNotFalse($stmtA);
        $this->assertSame(1, (int) $stmtA->fetchColumn());

        // Verify event does NOT exist in B
        $stmtB = $pdoB->query("SELECT COUNT(*) FROM webcal_entry WHERE cal_name = 'Alpha Only Event'");
        $this->assertNotFalse($stmtB);
        $this->assertSame(0, (int) $stmtB->fetchColumn());
    }

    public function testSuspendedTenantReturns403(): void
    {
        // Provision and then suspend
        $this->provisioner->provision('suspend-me', 'Suspend Me', 'a@b.com');

        $tenant = $this->repo->findBySlug('suspend-me');
        $this->assertNotNull($tenant);

        // Update status to suspended
        $suspended = new Tenant(
            $tenant->id(),
            $tenant->slug(),
            $tenant->name(),
            $tenant->dbHost(),
            $tenant->dbName(),
            $tenant->dbUser(),
            $tenant->dbPassword(),
            $tenant->plan(),
            TenantStatus::Suspended,
        );
        $this->repo->save($suspended);

        // Try to resolve — should get 403
        $this->context->reset();
        $event = $this->createRequestEvent('suspend-me.' . self::BASE_DOMAIN);
        $this->resolver->onKernelRequest($event);

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testDeleteTenantMakesSlugReusable(): void
    {
        // Provision
        $r1 = $this->provisioner->provision('reuse-me', 'Reuse', 'a@b.com');
        $this->assertTrue($r1->success, $r1->error);

        $tenant = $this->repo->findBySlug('reuse-me');
        $this->assertNotNull($tenant);

        // Delete from registry and clear cached connection
        $this->repo->delete($tenant->id());
        $this->dbManager->clearConnection('reuse-me');
        $this->assertNull($this->repo->findBySlug('reuse-me'));

        // Slug is now available for reuse
        $r2 = $this->provisioner->provision('reuse-me', 'Reuse Again', 'c@d.com');
        $this->assertTrue($r2->success, $r2->error);

        $tenant2 = $this->repo->findBySlug('reuse-me');
        $this->assertNotNull($tenant2);
        $this->assertSame('Reuse Again', $tenant2->name());
    }
}
