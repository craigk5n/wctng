<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenant;

use App\Tenant\Tenant;
use App\Tenant\TenantContext;
use App\Tenant\TenantDatabaseManager;
use App\Tenant\TenantJwtValidator;
use App\Tenant\TenantRepository;
use App\Tenant\TenantResolverListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Integration tests for the full tenant resolution chain:
 * subdomain → header → JWT validation → TenantContext → TenantDatabaseManager
 */
final class TenantResolutionChainTest extends TestCase
{
    private const BASE_DOMAIN = 'webcalendar.test';
    private const APP_SECRET = 'integration_test_secret_32chars!';

    private TenantContext $context;
    private TenantRepository $repo;
    private TenantDatabaseManager $dbManager;
    private TenantResolverListener $resolver;
    private TenantJwtValidator $jwtValidator;

    #[\Override]
    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec(TenantRepository::SCHEMA_SQL);

        $this->context = new TenantContext();
        $this->repo = new TenantRepository($pdo);
        $this->dbManager = new TenantDatabaseManager(self::APP_SECRET);
        $this->resolver = new TenantResolverListener($this->repo, $this->context, self::BASE_DOMAIN, 'hosted');
        $this->jwtValidator = new TenantJwtValidator($this->context);

        // Create test tenants
        $this->repo->save(new Tenant(0, 'alpha', 'Alpha Corp', '', ':memory:', '', '', 'pro', 'active'));
        $this->repo->save(new Tenant(0, 'beta', 'Beta Inc', '', ':memory:', '', '', 'free', 'active'));
        $this->repo->save(new Tenant(0, 'suspended-co', 'Suspended', '', ':memory:', '', '', 'free', 'suspended'));
    }

    private function createEvent(string $host, ?string $headerTenant = null, ?string $jwtTenant = null): RequestEvent
    {
        $request = Request::create('http://' . $host . '/api/v2/events');
        if ($headerTenant !== null) {
            $request->headers->set('X-Tenant-Id', $headerTenant);
        }
        if ($jwtTenant !== null) {
            $request->attributes->set('_jwt_tenant', $jwtTenant);
        }
        $kernel = $this->createMock(KernelInterface::class);

        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function runChain(RequestEvent $event): void
    {
        $this->resolver->onKernelRequest($event);
        if ($event->getResponse() === null) {
            $this->jwtValidator->onKernelRequest($event);
        }
    }

    // --- Subdomain resolution ---

    public function testSubdomainResolutionSetsTenantContext(): void
    {
        $event = $this->createEvent('alpha.' . self::BASE_DOMAIN);
        $this->runChain($event);

        $this->assertNull($event->getResponse());
        $this->assertNotNull($this->context->getTenant());
        $this->assertSame('alpha', $this->context->getTenant()?->slug());
        $this->assertSame('Alpha Corp', $this->context->getTenant()?->name());
    }

    public function testSubdomainResolutionCreatesPdo(): void
    {
        $event = $this->createEvent('alpha.' . self::BASE_DOMAIN);
        $this->runChain($event);

        $tenant = $this->context->getTenantOrFail();
        $pdo = $this->dbManager->getConnection($tenant);
        $this->assertInstanceOf(\PDO::class, $pdo);
    }

    // --- Header resolution ---

    public function testHeaderResolutionWorksForApiClients(): void
    {
        $event = $this->createEvent('localhost', 'beta');
        $this->runChain($event);

        $this->assertNull($event->getResponse());
        $this->assertNotNull($this->context->getTenant());
        $this->assertSame('beta', $this->context->getTenant()?->slug());
    }

    // --- Standalone mode ---

    public function testStandaloneModeUsesDefaultDatabase(): void
    {
        $standaloneResolver = new TenantResolverListener($this->repo, $this->context, self::BASE_DOMAIN, 'standalone');

        $event = $this->createEvent('alpha.' . self::BASE_DOMAIN);
        $standaloneResolver->onKernelRequest($event);

        $this->assertNull($event->getResponse());
        $this->assertNull($this->context->getTenant());
        $this->assertFalse($this->context->isMultiTenant());
    }

    // --- Invalid/suspended tenant ---

    public function testInvalidTenantReturns404(): void
    {
        $event = $this->createEvent('nonexistent.' . self::BASE_DOMAIN);
        $this->runChain($event);

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(404, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertSame(404, $body['error']['code']);
    }

    public function testSuspendedTenantReturns403(): void
    {
        $event = $this->createEvent('suspended-co.' . self::BASE_DOMAIN);
        $this->runChain($event);

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testInvalidTenantViaHeaderReturns404(): void
    {
        $event = $this->createEvent('localhost', 'does-not-exist');
        $this->runChain($event);

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(404, $response->getStatusCode());
    }

    // --- Cross-tenant isolation ---

    public function testCrossTenantDataIsolation(): void
    {
        // Resolve tenant alpha
        $eventA = $this->createEvent('alpha.' . self::BASE_DOMAIN);
        $this->runChain($eventA);
        $tenantA = $this->context->getTenantOrFail();

        $pdoA = $this->dbManager->getConnection($tenantA);

        // Reset context for tenant beta
        $this->context->reset();

        $eventB = $this->createEvent('beta.' . self::BASE_DOMAIN);
        $this->runChain($eventB);
        $tenantB = $this->context->getTenantOrFail();

        $pdoB = $this->dbManager->getConnection($tenantB);

        // Different PDO instances = isolated databases
        $this->assertNotSame($pdoA, $pdoB);
        $this->assertSame('alpha', $tenantA->slug());
        $this->assertSame('beta', $tenantB->slug());
    }

    // --- JWT mismatch ---

    public function testJwtMismatchReturns403(): void
    {
        // Resolve via subdomain as "alpha", but JWT says "beta"
        $event = $this->createEvent('alpha.' . self::BASE_DOMAIN, null, 'beta');
        $this->runChain($event);

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testJwtMatchPasses(): void
    {
        $event = $this->createEvent('alpha.' . self::BASE_DOMAIN, null, 'alpha');
        $this->runChain($event);

        $this->assertNull($event->getResponse());
        $this->assertSame('alpha', $this->context->getTenant()?->slug());
    }
}
