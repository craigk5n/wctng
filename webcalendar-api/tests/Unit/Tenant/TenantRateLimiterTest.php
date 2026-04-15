<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenant;

use App\Tenant\Tenant;
use App\Tenant\TenantContext;
use App\Tenant\TenantPlan;
use App\Tenant\TenantRateLimiter;
use App\Tenant\TenantStatus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

final class TenantRateLimiterTest extends TestCase
{
    private string $tmpDir;
    private TenantContext $context;

    #[\Override]
    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/wctng_rate_test_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir);
        $this->context = new TenantContext();
    }

    #[\Override]
    protected function tearDown(): void
    {
        // Clean up
        $files = glob($this->tmpDir . '/rate_limits/*');
        if (\is_array($files)) {
            foreach ($files as $f) {
                unlink($f);
            }
        }
        @rmdir($this->tmpDir . '/rate_limits');
        @rmdir($this->tmpDir);
    }

    private function createRequestEvent(): RequestEvent
    {
        $request = Request::create('/api/v2/events');
        $kernel = $this->createMock(KernelInterface::class);
        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function createResponseEvent(Request $request): ResponseEvent
    {
        $kernel = $this->createMock(KernelInterface::class);
        return new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response());
    }

    public function testSkipsInStandaloneMode(): void
    {
        $limiter = new TenantRateLimiter($this->context, $this->tmpDir);
        $event = $this->createRequestEvent();

        $limiter->onKernelRequest($event);

        $this->assertNull($event->getResponse());
    }

    public function testAllowsRequestsUnderLimit(): void
    {
        $this->context->setTenant(new Tenant(1, 'rate-ok', 'OK', '', '', '', '', TenantPlan::Free, TenantStatus::Active));
        $limiter = new TenantRateLimiter($this->context, $this->tmpDir);

        $event = $this->createRequestEvent();
        $limiter->onKernelRequest($event);

        $this->assertNull($event->getResponse());
    }

    public function testAddsRateLimitHeaders(): void
    {
        $this->context->setTenant(new Tenant(1, 'rate-hdr', 'Headers', '', '', '', '', TenantPlan::Pro, TenantStatus::Active));
        $limiter = new TenantRateLimiter($this->context, $this->tmpDir);

        $requestEvent = $this->createRequestEvent();
        $limiter->onKernelRequest($requestEvent);

        $responseEvent = $this->createResponseEvent($requestEvent->getRequest());
        $limiter->onKernelResponse($responseEvent);

        $response = $responseEvent->getResponse();
        $this->assertSame('1000', $response->headers->get('X-RateLimit-Limit'));
        $this->assertNotNull($response->headers->get('X-RateLimit-Remaining'));
        $this->assertNotNull($response->headers->get('X-RateLimit-Reset'));
    }

    public function testReturns429WhenLimitExceeded(): void
    {
        // Use a tenant with very low custom limit — we'll simulate by making many requests
        // Free plan = 100 requests. We'll create a limiter and exhaust it.
        $this->context->setTenant(new Tenant(1, 'rate-exceeded', 'Exceeded', '', '', '', '', TenantPlan::Free, TenantStatus::Active));

        // Pre-fill the counter file to simulate 100 requests already made
        $dir = $this->tmpDir . '/rate_limits';
        mkdir($dir, 0o777, true);
        $window = (int) (floor(time() / 60) * 60);
        file_put_contents($dir . '/rate-exceeded_' . $window . '.count', '100');

        $limiter = new TenantRateLimiter($this->context, $this->tmpDir);
        $event = $this->createRequestEvent();
        $limiter->onKernelRequest($event);

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('0', $response->headers->get('X-RateLimit-Remaining'));
    }

    public function testDifferentPlansHaveDifferentLimits(): void
    {
        // Free plan
        $this->context->setTenant(new Tenant(1, 'plan-free', 'Free', '', '', '', '', TenantPlan::Free, TenantStatus::Active));
        $limiterFree = new TenantRateLimiter($this->context, $this->tmpDir);
        $eventFree = $this->createRequestEvent();
        $limiterFree->onKernelRequest($eventFree);
        $responseFree = $this->createResponseEvent($eventFree->getRequest());
        $limiterFree->onKernelResponse($responseFree);
        $this->assertSame('100', $responseFree->getResponse()->headers->get('X-RateLimit-Limit'));

        // Pro plan
        $this->context->reset();
        $this->context->setTenant(new Tenant(2, 'plan-pro', 'Pro', '', '', '', '', TenantPlan::Pro, TenantStatus::Active));
        $limiterPro = new TenantRateLimiter($this->context, $this->tmpDir);
        $eventPro = $this->createRequestEvent();
        $limiterPro->onKernelRequest($eventPro);
        $responsePro = $this->createResponseEvent($eventPro->getRequest());
        $limiterPro->onKernelResponse($responsePro);
        $this->assertSame('1000', $responsePro->getResponse()->headers->get('X-RateLimit-Limit'));
    }
}
