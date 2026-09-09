<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenant;

use App\Tenant\FileTenantRateLimitStorage;
use App\Tenant\Tenant;
use App\Tenant\TenantContext;
use App\Tenant\TenantPlan;
use App\Tenant\TenantRateLimiter;
use App\Tenant\TenantStatus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
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
    private FileTenantRateLimitStorage $storage;

    #[\Override]
    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/wctng_rate_test_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir);
        $this->context = new TenantContext();
        $this->storage = new FileTenantRateLimitStorage($this->tmpDir);
    }

    #[\Override]
    protected function tearDown(): void
    {
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
        $limiter = new TenantRateLimiter($this->context, $this->storage);
        $event = $this->createRequestEvent();

        $limiter->onKernelRequest($event);

        $this->assertNull($event->getResponse());
    }

    public function testAllowsRequestsUnderLimit(): void
    {
        $this->context->setTenant(new Tenant(1, 'rate-ok', 'OK', '', '', '', '', TenantPlan::Free, TenantStatus::Active));
        $limiter = new TenantRateLimiter($this->context, $this->storage);

        $event = $this->createRequestEvent();
        $limiter->onKernelRequest($event);

        $this->assertNull($event->getResponse());
    }

    public function testAddsRateLimitHeaders(): void
    {
        $this->context->setTenant(new Tenant(1, 'rate-hdr', 'Headers', '', '', '', '', TenantPlan::Pro, TenantStatus::Active));
        $limiter = new TenantRateLimiter($this->context, $this->storage);

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
        $this->context->setTenant(new Tenant(1, 'rate-exceeded', 'Exceeded', '', '', '', '', TenantPlan::Free, TenantStatus::Active));

        $dir = $this->tmpDir . '/rate_limits';
        mkdir($dir, 0o777, true);
        $window = (int) (floor(time() / 60) * 60);
        file_put_contents($dir . '/rate-exceeded_' . $window . '.count', '100');

        $limiter = new TenantRateLimiter($this->context, $this->storage);
        $event = $this->createRequestEvent();
        $limiter->onKernelRequest($event);

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('0', $response->headers->get('X-RateLimit-Remaining'));
    }

    public function testTheCounterStartsAgainWhenTheMinuteWindowRollsOver(): void
    {
        // Only reachable with an injected clock: before it, checking this
        // meant waiting for a real minute boundary to pass.
        $clock = new MockClock('2026-03-15T10:00:30+00:00');
        $this->context->setTenant(new Tenant(1, 'rate-window', 'Window', '', '', '', '', TenantPlan::Free, TenantStatus::Active));

        $dir = $this->tmpDir . '/rate_limits';
        mkdir($dir, 0o777, true);
        $window = (new \DateTimeImmutable('2026-03-15T10:00:00+00:00'))->getTimestamp();
        file_put_contents($dir . '/rate-window_' . $window . '.count', '100');

        $limiter = new TenantRateLimiter($this->context, $this->storage, $clock);

        $exhausted = $this->createRequestEvent();
        $limiter->onKernelRequest($exhausted);
        $response = $exhausted->getResponse();
        self::assertNotNull($response);
        self::assertSame(429, $response->getStatusCode());

        // 10:00:30 plus 30 seconds is the start of the next window.
        $clock->sleep(30);

        $fresh = $this->createRequestEvent();
        $limiter->onKernelRequest($fresh);
        self::assertNull($fresh->getResponse(), 'the next minute is counted separately');
    }

    public function testResetHeaderIsTheEndOfTheCurrentWindow(): void
    {
        $clock = new MockClock('2026-03-15T10:00:30+00:00');
        $this->context->setTenant(new Tenant(1, 'rate-reset', 'Reset', '', '', '', '', TenantPlan::Free, TenantStatus::Active));
        $limiter = new TenantRateLimiter($this->context, $this->storage, $clock);

        $requestEvent = $this->createRequestEvent();
        $limiter->onKernelRequest($requestEvent);
        $responseEvent = $this->createResponseEvent($requestEvent->getRequest());
        $limiter->onKernelResponse($responseEvent);

        // The window holding 10:00:30 ends at 10:01:00, not 60 seconds from
        // the request.
        self::assertSame(
            (string) (new \DateTimeImmutable('2026-03-15T10:01:00+00:00'))->getTimestamp(),
            $responseEvent->getResponse()->headers->get('X-RateLimit-Reset'),
        );
    }

    public function testDifferentPlansHaveDifferentLimits(): void
    {
        $this->context->setTenant(new Tenant(1, 'plan-free', 'Free', '', '', '', '', TenantPlan::Free, TenantStatus::Active));
        $limiterFree = new TenantRateLimiter($this->context, $this->storage);
        $eventFree = $this->createRequestEvent();
        $limiterFree->onKernelRequest($eventFree);
        $responseFree = $this->createResponseEvent($eventFree->getRequest());
        $limiterFree->onKernelResponse($responseFree);
        $this->assertSame('100', $responseFree->getResponse()->headers->get('X-RateLimit-Limit'));

        $this->context->reset();
        $this->context->setTenant(new Tenant(2, 'plan-pro', 'Pro', '', '', '', '', TenantPlan::Pro, TenantStatus::Active));
        $limiterPro = new TenantRateLimiter($this->context, $this->storage);
        $eventPro = $this->createRequestEvent();
        $limiterPro->onKernelRequest($eventPro);
        $responsePro = $this->createResponseEvent($eventPro->getRequest());
        $limiterPro->onKernelResponse($responsePro);
        $this->assertSame('1000', $responsePro->getResponse()->headers->get('X-RateLimit-Limit'));
    }
}
