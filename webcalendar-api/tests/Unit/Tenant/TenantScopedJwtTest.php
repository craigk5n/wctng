<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenant;

use App\Security\JwtTenantExtractor;
use App\Tenant\Tenant;
use App\Tenant\TenantContext;
use App\Tenant\TenantJwtValidator;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTDecodedEvent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

final class TenantScopedJwtTest extends TestCase
{
    public function testExtractorSetsTenantAttribute(): void
    {
        $request = Request::create('/api/v2/events');
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $extractor = new JwtTenantExtractor($requestStack);

        $event = new JWTDecodedEvent(['username' => 'admin', 'tenant' => 'acme']);
        $extractor($event);

        $this->assertSame('acme', $request->attributes->get('_jwt_tenant'));
    }

    public function testExtractorSkipsWhenNoTenantClaim(): void
    {
        $request = Request::create('/api/v2/events');
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $extractor = new JwtTenantExtractor($requestStack);

        $event = new JWTDecodedEvent(['username' => 'admin']);
        $extractor($event);

        $this->assertNull($request->attributes->get('_jwt_tenant'));
    }

    public function testValidatorPassesWhenClaimMatchesTenant(): void
    {
        $context = new TenantContext();
        $context->setTenant(new Tenant(1, 'acme', 'Acme', '', '', '', '', 'pro', 'active'));

        $validator = new TenantJwtValidator($context);

        $request = Request::create('/api/v2/events');
        $request->attributes->set('_jwt_tenant', 'acme');

        $kernel = $this->createMock(KernelInterface::class);
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $validator->onKernelRequest($event);

        $this->assertNull($event->getResponse());
    }

    public function testValidatorRejects403WhenClaimMismatch(): void
    {
        $context = new TenantContext();
        $context->setTenant(new Tenant(1, 'acme', 'Acme', '', '', '', '', 'pro', 'active'));

        $validator = new TenantJwtValidator($context);

        $request = Request::create('/api/v2/events');
        $request->attributes->set('_jwt_tenant', 'globex');

        $kernel = $this->createMock(KernelInterface::class);
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $validator->onKernelRequest($event);

        $this->assertNotNull($event->getResponse());
        $this->assertSame(403, $event->getResponse()?->getStatusCode());
    }

    public function testValidatorSkipsInStandaloneMode(): void
    {
        $context = new TenantContext();
        // No tenant set = standalone mode

        $validator = new TenantJwtValidator($context);

        $request = Request::create('/api/v2/events');
        // No _jwt_tenant attribute either

        $kernel = $this->createMock(KernelInterface::class);
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $validator->onKernelRequest($event);

        $this->assertNull($event->getResponse());
    }
}
