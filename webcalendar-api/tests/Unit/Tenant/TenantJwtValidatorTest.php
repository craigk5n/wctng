<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenant;

use App\Tenant\Tenant;
use App\Tenant\TenantContext;
use App\Tenant\TenantJwtValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

final class TenantJwtValidatorTest extends TestCase
{
    private function createEvent(Request $request): RequestEvent
    {
        $kernel = $this->createMock(KernelInterface::class);
        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    public function testSkipsWhenNoTenantContext(): void
    {
        $context = new TenantContext();
        $validator = new TenantJwtValidator($context);

        $request = Request::create('/api/v2/events');
        $event = $this->createEvent($request);

        $validator->onKernelRequest($event);

        // No response set = no error
        $this->assertNull($event->getResponse());
    }

    public function testSkipsWhenNoJwtTenantClaim(): void
    {
        $context = new TenantContext();
        $context->setTenant(new Tenant(1, 'acme', 'Acme', '', '', '', '', 'pro', 'active'));
        $validator = new TenantJwtValidator($context);

        $request = Request::create('/api/v2/events');
        // No JWT attributes set
        $event = $this->createEvent($request);

        $validator->onKernelRequest($event);

        $this->assertNull($event->getResponse());
    }

    public function testMatchingTenantClaimPasses(): void
    {
        $context = new TenantContext();
        $context->setTenant(new Tenant(1, 'acme', 'Acme', '', '', '', '', 'pro', 'active'));
        $validator = new TenantJwtValidator($context);

        $request = Request::create('/api/v2/events');
        $request->attributes->set('_jwt_tenant', 'acme');
        $event = $this->createEvent($request);

        $validator->onKernelRequest($event);

        $this->assertNull($event->getResponse());
    }

    public function testMismatchedTenantClaimReturns403(): void
    {
        $context = new TenantContext();
        $context->setTenant(new Tenant(1, 'acme', 'Acme', '', '', '', '', 'pro', 'active'));
        $validator = new TenantJwtValidator($context);

        $request = Request::create('/api/v2/events');
        $request->attributes->set('_jwt_tenant', 'globex');
        $event = $this->createEvent($request);

        $validator->onKernelRequest($event);

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(403, $response->getStatusCode());
    }
}
