<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenant;

use App\Tenant\ControlPlaneGuard;
use App\Tenant\Tenant;
use App\Tenant\TenantContext;
use App\Tenant\TenantPlan;
use App\Tenant\TenantRepository;
use App\Tenant\TenantResolverListener;
use App\Tenant\TenantStatus;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

final class ModeDetectionTest extends TestCase
{
    private function createEvent(string $path): RequestEvent
    {
        $request = Request::create($path);
        $kernel = $this->createMock(KernelInterface::class);
        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    public function testStandaloneModeSkipsTenantResolution(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec(TenantRepository::SCHEMA_SQL);
        $repo = new TenantRepository($pdo);
        $repo->save(new Tenant(0, 'acme', 'Acme', '', ':memory:', '', '', TenantPlan::Pro, TenantStatus::Active));

        $context = new TenantContext();
        $resolver = new TenantResolverListener($repo, $context, 'webcalendar.com', 'standalone');

        $request = Request::create('http://acme.webcalendar.com/api/v2/events');
        $kernel = $this->createMock(KernelInterface::class);
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $resolver->onKernelRequest($event);

        $this->assertNull($context->getTenant());
        $this->assertNull($event->getResponse());
    }

    public function testHostedModeResolvesTenant(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec(TenantRepository::SCHEMA_SQL);
        $repo = new TenantRepository($pdo);
        $repo->save(new Tenant(0, 'acme', 'Acme', '', ':memory:', '', '', TenantPlan::Pro, TenantStatus::Active));

        $context = new TenantContext();
        $resolver = new TenantResolverListener($repo, $context, 'webcalendar.com', 'hosted');

        $request = Request::create('http://acme.webcalendar.com/api/v2/events');
        $kernel = $this->createMock(KernelInterface::class);
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $resolver->onKernelRequest($event);

        $this->assertNotNull($context->getTenant());
        $this->assertSame('acme', $context->getTenant()?->slug());
    }

    public function testControlPlaneBlockedInStandaloneMode(): void
    {
        $jwtEncoder = $this->createMock(JWTEncoderInterface::class);
        $guard = new ControlPlaneGuard($jwtEncoder, 'standalone');

        $event = $this->createEvent('/control/v1/tenants');
        $guard->onKernelRequest($event);

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testControlPlaneAllowedInHostedMode(): void
    {
        $jwtEncoder = $this->createMock(JWTEncoderInterface::class);
        $guard = new ControlPlaneGuard($jwtEncoder, 'hosted');

        // Without auth header — should get 401 (not 404)
        $event = $this->createEvent('/control/v1/tenants');
        $guard->onKernelRequest($event);

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(401, $response->getStatusCode());
    }

    public function testStandaloneModeUsesDefaultPdo(): void
    {
        $context = new TenantContext();
        // No tenant set = standalone mode

        $this->assertFalse($context->isMultiTenant());
        $this->assertNull($context->getTenant());
    }
}
