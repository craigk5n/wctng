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

final class TenantResolverListenerTest extends TestCase
{
    private TenantContext $context;
    private \PDO $pdo;
    private TenantRepository $repo;

    #[\Override]
    protected function setUp(): void
    {
        $this->context = new TenantContext();
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(TenantRepository::SCHEMA_SQL);
        $this->repo = new TenantRepository($this->pdo);
    }

    private function createEvent(string $host): RequestEvent
    {
        $request = Request::create('http://' . $host . '/api/v2/events');
        $kernel = $this->createMock(KernelInterface::class);

        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    public function testResolvesSubdomain(): void
    {
        $this->repo->save(new Tenant(0, 'acme', 'Acme', '', ':memory:', '', '', TenantPlan::Pro, TenantStatus::Active));

        $listener = new TenantResolverListener($this->repo, $this->context, 'webcalendar.com', 'hosted');
        $event = $this->createEvent('acme.webcalendar.com');

        $listener->onKernelRequest($event);

        $this->assertNotNull($this->context->getTenant());
        $this->assertSame('acme', $this->context->getTenant()?->slug());
    }

    public function testReturns404ForUnknownSlug(): void
    {
        $listener = new TenantResolverListener($this->repo, $this->context, 'webcalendar.com', 'hosted');
        $event = $this->createEvent('unknown.webcalendar.com');

        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testSkipsInStandaloneMode(): void
    {
        $listener = new TenantResolverListener($this->repo, $this->context, 'webcalendar.com', 'standalone');
        $event = $this->createEvent('acme.webcalendar.com');

        $listener->onKernelRequest($event);

        $this->assertNull($this->context->getTenant());
        $this->assertNull($event->getResponse());
    }

    public function testSkipsWhenHostIsBaseDomain(): void
    {
        $listener = new TenantResolverListener($this->repo, $this->context, 'webcalendar.com', 'hosted');
        $event = $this->createEvent('webcalendar.com');

        $listener->onKernelRequest($event);

        $this->assertNull($this->context->getTenant());
        $this->assertNull($event->getResponse());
    }

    public function testSkipsSubRequestEvents(): void
    {
        $this->repo->save(new Tenant(0, 'acme', 'Acme', '', ':memory:', '', '', TenantPlan::Pro, TenantStatus::Active));
        $listener = new TenantResolverListener($this->repo, $this->context, 'webcalendar.com', 'hosted');

        $request = Request::create('http://acme.webcalendar.com/api/v2/events');
        $kernel = $this->createMock(KernelInterface::class);
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST);

        $listener->onKernelRequest($event);

        $this->assertNull($this->context->getTenant());
    }

    public function testRejects404ForSuspendedTenant(): void
    {
        $this->repo->save(new Tenant(0, 'suspended-co', 'Suspended', '', ':memory:', '', '', TenantPlan::Pro, TenantStatus::Suspended));

        $listener = new TenantResolverListener($this->repo, $this->context, 'webcalendar.com', 'hosted');
        $event = $this->createEvent('suspended-co.webcalendar.com');

        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(403, $response->getStatusCode());
    }
}
