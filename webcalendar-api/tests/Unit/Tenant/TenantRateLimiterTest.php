<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenant;

use App\Tenant\FileTenantRateLimitStorage;
use App\Tenant\Tenant;
use App\Tenant\TenantContext;
use App\Tenant\TenantPlan;
use App\Tenant\TenantRateLimiter;
use App\Tenant\TenantRateLimitStorage;
use App\Tenant\TenantStatus;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /** Seeds the counter for a slug's current window. */
    private function seedCount(string $slug, int $count, string $now = 'now'): void
    {
        $dir = $this->tmpDir . '/rate_limits';
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        $window = intdiv((new \DateTimeImmutable($now))->getTimestamp(), 60) * 60;
        file_put_contents($dir . '/' . $slug . '_' . $window . '.count', (string) $count);
    }

    private function limiterFor(TenantPlan $plan, string $slug, ?MockClock $clock = null): TenantRateLimiter
    {
        $this->context->reset();
        $this->context->setTenant(new Tenant(1, $slug, $slug, '', '', '', '', $plan, TenantStatus::Active));

        return $clock === null
            ? new TenantRateLimiter($this->context, $this->storage)
            : new TenantRateLimiter($this->context, $this->storage, $clock);
    }

    /** Runs the request listener, then the response listener, and returns the response. */
    private function throughBothListeners(TenantRateLimiter $limiter): Response
    {
        $requestEvent = $this->createRequestEvent();
        $limiter->onKernelRequest($requestEvent);
        $responseEvent = $this->createResponseEvent($requestEvent->getRequest());
        $limiter->onKernelResponse($responseEvent);

        return $responseEvent->getResponse();
    }

    // --------------------------------------------------------- every plan

    /** @return iterable<string, array{TenantPlan, string}> */
    public static function plansAndTheirLimits(): iterable
    {
        // Enterprise was never exercised, so its match arm could be deleted
        // and its ceiling moved without a failure.
        yield 'free' => [TenantPlan::Free, '100'];
        yield 'pro' => [TenantPlan::Pro, '1000'];
        yield 'enterprise' => [TenantPlan::Enterprise, '5000'];
    }

    #[DataProvider('plansAndTheirLimits')]
    public function testEachPlanAdvertisesItsOwnCeiling(TenantPlan $plan, string $expected): void
    {
        $response = $this->throughBothListeners($this->limiterFor($plan, 'plan-' . $plan->value));

        self::assertSame($expected, $response->headers->get('X-RateLimit-Limit'));
    }

    // ---------------------------------------------------- what is left

    public function testRemainingCountsDownFromTheLimit(): void
    {
        // Nothing asserted this header's value -- only that it was present --
        // which left the whole subtraction unpinned: adding instead of
        // subtracting, or always reporting zero, both looked correct.
        $limiter = $this->limiterFor(TenantPlan::Free, 'rate-countdown');

        self::assertSame('99', $this->throughBothListeners($limiter)->headers->get('X-RateLimit-Remaining'));
        self::assertSame('98', $this->throughBothListeners($limiter)->headers->get('X-RateLimit-Remaining'));
    }

    public function testRemainingStopsAtZeroRatherThanGoingNegative(): void
    {
        // Past the ceiling the subtraction is negative, and a negative
        // X-RateLimit-Remaining is not a thing a client can act on.
        $this->seedCount('rate-negative', 150);
        $limiter = $this->limiterFor(TenantPlan::Free, 'rate-negative');

        self::assertSame('0', $this->throughBothListeners($limiter)->headers->get('X-RateLimit-Remaining'));
    }

    // ------------------------------------------------------- the boundary

    public function testTheRequestThatExactlyReachesTheLimitIsAllowed(): void
    {
        // A Free tenant gets 100 requests a minute, so the hundredth is
        // inside the allowance and the hundred-and-first is not. The existing
        // 429 case seeds the counter at the limit, which lands on 101 and so
        // passes whether the comparison is > or >=; with >= the last
        // legitimate request of every window is rejected.
        $this->seedCount('rate-boundary', 99);
        $limiter = $this->limiterFor(TenantPlan::Free, 'rate-boundary');

        $event = $this->createRequestEvent();
        $limiter->onKernelRequest($event);

        self::assertNull($event->getResponse(), 'the hundredth request is still within the allowance');
        self::assertSame('0', $this->throughBothListeners($limiter)->headers->get('X-RateLimit-Remaining'));
    }

    public function testTheRequestAfterTheLimitIsRefused(): void
    {
        $this->seedCount('rate-over', 100);
        $limiter = $this->limiterFor(TenantPlan::Free, 'rate-over');

        $event = $this->createRequestEvent();
        $limiter->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(429, $response->getStatusCode());
    }

    // ------------------------------------------------ what the 429 says

    public function testTheRefusalUsesTheProjectsErrorEnvelope(): void
    {
        // Clients parse every error the same way, so a 429 that omits the
        // data or meta keys is one their error handling does not recognise.
        $this->seedCount('rate-body', 100);
        $limiter = $this->limiterFor(TenantPlan::Free, 'rate-body');

        $event = $this->createRequestEvent();
        $limiter->onKernelRequest($event);
        $response = $event->getResponse();
        self::assertNotNull($response);

        self::assertSame(
            [
                'data' => null,
                'meta' => null,
                'error' => ['code' => 429, 'message' => 'Rate limit exceeded', 'details' => []],
            ],
            json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR),
        );
    }

    public function testTheRefusalCarriesItsOwnHeadersWithoutHelpFromTheResponseListener(): void
    {
        // onKernelRequest short-circuits the kernel, so these are set on the
        // spot. The response listener happens to set the same three again,
        // which is why dropping them here was invisible -- but Retry-After is
        // only ever set here, and without it a client has nothing to back off
        // against.
        $clock = new MockClock('2026-03-15T10:00:30+00:00');
        $this->seedCount('rate-headers', 100, '2026-03-15T10:00:30+00:00');
        $limiter = $this->limiterFor(TenantPlan::Free, 'rate-headers', $clock);

        $event = $this->createRequestEvent();
        $limiter->onKernelRequest($event);
        $response = $event->getResponse();
        self::assertNotNull($response);

        self::assertSame('100', $response->headers->get('X-RateLimit-Limit'));
        self::assertSame('0', $response->headers->get('X-RateLimit-Remaining'));
        self::assertSame(
            (string) (new \DateTimeImmutable('2026-03-15T10:01:00+00:00'))->getTimestamp(),
            $response->headers->get('X-RateLimit-Reset'),
        );
        self::assertSame('60', $response->headers->get('Retry-After'));
    }

    public function testHeadersStillReadSensiblyWhenTheCounterBackendFails(): void
    {
        // The listener assigns the limit before it touches storage, so a
        // backend that throws -- Redis unreachable, the counter directory
        // unwritable -- leaves the instance with a limit but no count. The
        // kernel turns the exception into a 500 and still dispatches
        // kernel.response through this same listener, which is when the
        // `?? 0` behind remaining is read. It has to say zero: a negative or
        // off-by-one remaining on an error response is worse than no number.
        // The reset stamp survives, because it is computed before the
        // increment rather than after it.
        $this->context->reset();
        $this->context->setTenant(
            new Tenant(1, 'rate-broken', 'Broken', '', '', '', '', TenantPlan::Free, TenantStatus::Active),
        );
        $clock = new MockClock('2026-03-15T10:00:30+00:00');
        $limiter = new TenantRateLimiter($this->context, new ThrowingRateLimitStorage(), $clock);

        $requestEvent = $this->createRequestEvent();

        try {
            $limiter->onKernelRequest($requestEvent);
            self::fail('the storage failure should not be swallowed');
        } catch (\RuntimeException) {
            // As the kernel would see it.
        }

        $responseEvent = $this->createResponseEvent($requestEvent->getRequest());
        $limiter->onKernelResponse($responseEvent);
        $response = $responseEvent->getResponse();

        self::assertSame('100', $response->headers->get('X-RateLimit-Limit'));
        self::assertSame('0', $response->headers->get('X-RateLimit-Remaining'));
        self::assertSame(
            (string) (new \DateTimeImmutable('2026-03-15T10:01:00+00:00'))->getTimestamp(),
            $response->headers->get('X-RateLimit-Reset'),
        );
    }

    // ------------------------------------------ state between two requests

    public function testARequestWithNoTenantCarriesNoRateLimitHeaders(): void
    {
        // The listener holds the figures for the request in hand, and a
        // container that serves two requests reuses the object. A base-domain
        // request resolves no tenant, so it must not be handed the previous
        // tenant's plan and remaining quota -- their usage, on somebody else's
        // response.
        $limiter = $this->limiterFor(TenantPlan::Pro, 'rate-stale-pro');
        $first = $this->throughBothListeners($limiter);
        self::assertSame('1000', $first->headers->get('X-RateLimit-Limit'));

        // The next request resolves no tenant at all.
        $this->context->reset();
        $second = $this->throughBothListeners($limiter);

        self::assertNull($second->headers->get('X-RateLimit-Limit'));
        self::assertNull($second->headers->get('X-RateLimit-Remaining'));
        self::assertNull($second->headers->get('X-RateLimit-Reset'));
    }

    public function testASecondTenantIsToldItsOwnCeilingAndNotTheFirstTenants(): void
    {
        // Pro then Free through one listener: the Free tenant must be told 100.
        $limiter = $this->limiterFor(TenantPlan::Pro, 'rate-first-pro');
        $this->throughBothListeners($limiter);

        // Same listener object, a different tenant in context.
        $this->context->reset();
        $this->context->setTenant(
            new Tenant(2, 'rate-second-free', 'Free', '', '', '', '', TenantPlan::Free, TenantStatus::Active),
        );
        $second = $this->throughBothListeners($limiter);

        self::assertSame('100', $second->headers->get('X-RateLimit-Limit'));
        self::assertSame('99', $second->headers->get('X-RateLimit-Remaining'));
    }
}

/** A counting backend that is down. */
final class ThrowingRateLimitStorage implements TenantRateLimitStorage
{
    #[\Override]
    public function incrementAndCount(string $slug, int $window): int
    {
        throw new \RuntimeException('counter backend unavailable');
    }

}
