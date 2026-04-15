<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenant;

use App\Tenant\Tenant;
use App\Tenant\TenantContext;
use App\Tenant\TenantPlan;
use App\Tenant\TenantRepository;
use App\Tenant\TenantResolverListener;
use App\Tenant\TenantStatus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

final class TenantHeaderResolverTest extends TestCase
{
    private TenantContext $context;
    private TenantRepository $repo;

    #[\Override]
    protected function setUp(): void
    {
        $this->context = new TenantContext();
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec(TenantRepository::SCHEMA_SQL);
        $this->repo = new TenantRepository($pdo);

        $this->repo->save(new Tenant(0, 'acme', 'Acme', '', ':memory:', '', '', TenantPlan::Pro, TenantStatus::Active));
        $this->repo->save(new Tenant(0, 'globex', 'Globex', '', ':memory:', '', '', TenantPlan::Free, TenantStatus::Active));
    }

    private function createEvent(string $host, ?string $tenantHeader = null): RequestEvent
    {
        $request = Request::create('http://' . $host . '/api/v2/events');
        if ($tenantHeader !== null) {
            $request->headers->set('X-Tenant-Id', $tenantHeader);
        }
        $kernel = $this->createMock(KernelInterface::class);

        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    public function testHeaderFallbackWhenNoSubdomain(): void
    {
        $listener = new TenantResolverListener($this->repo, $this->context, 'webcalendar.com', 'hosted');

        // Host is the base domain (no subdomain), but header is set
        $event = $this->createEvent('webcalendar.com', 'acme');
        $listener->onKernelRequest($event);

        $this->assertNotNull($this->context->getTenant());
        $this->assertSame('acme', $this->context->getTenant()?->slug());
    }

    public function testHeaderFallbackWhenHostDoesNotMatchBaseDomain(): void
    {
        $listener = new TenantResolverListener($this->repo, $this->context, 'webcalendar.com', 'hosted');

        // Host is localhost (not base domain), header provides tenant
        $event = $this->createEvent('localhost', 'globex');
        $listener->onKernelRequest($event);

        $this->assertNotNull($this->context->getTenant());
        $this->assertSame('globex', $this->context->getTenant()?->slug());
    }

    public function testSubdomainTakesPriorityOverHeader(): void
    {
        $listener = new TenantResolverListener($this->repo, $this->context, 'webcalendar.com', 'hosted');

        // Both subdomain and header set — subdomain wins
        $event = $this->createEvent('acme.webcalendar.com', 'globex');
        $listener->onKernelRequest($event);

        $this->assertNotNull($this->context->getTenant());
        $this->assertSame('acme', $this->context->getTenant()?->slug());
    }

    public function testHeaderWithUnknownTenantReturns404(): void
    {
        $listener = new TenantResolverListener($this->repo, $this->context, 'webcalendar.com', 'hosted');

        $event = $this->createEvent('webcalendar.com', 'nonexistent');
        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testNoSubdomainNoHeaderNoResolution(): void
    {
        $listener = new TenantResolverListener($this->repo, $this->context, 'webcalendar.com', 'hosted');

        // Base domain, no header
        $event = $this->createEvent('webcalendar.com');
        $listener->onKernelRequest($event);

        $this->assertNull($this->context->getTenant());
        $this->assertNull($event->getResponse());
    }
}
