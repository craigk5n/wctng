<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\EventSubscriber\RequestIdSubscriber;
use App\Monolog\RequestIdProcessor;
use App\Tenant\Tenant;
use App\Tenant\TenantContext;
use App\Tenant\TenantPlan;
use App\Tenant\TenantStatus;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * What every log record is stamped with.
 *
 * The request id and the tenant are attached here, when the record is written,
 * rather than when the request starts: RequestIdSubscriber runs ahead of
 * TenantResolverListener so that a refused request still gets an id, which
 * means it has no tenant to log. Anything written from a controller does.
 */
final class RequestIdProcessorTest extends TestCase
{
    /** @param array<string, mixed> $extra */
    private static function record(array $extra = []): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), 'app', Level::Info, 'something happened', [], $extra);
    }

    private function subscriberWithAnId(): RequestIdSubscriber
    {
        $subscriber = new RequestIdSubscriber(new NullLogger());
        $request = Request::create('/api/v2/events');
        $request->headers->set('X-Request-Id', 'req-abc');
        $subscriber->onRequest(
            new RequestEvent($this->createMock(KernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST),
        );

        return $subscriber;
    }

    private static function tenantContext(?string $slug): TenantContext
    {
        $context = new TenantContext();
        if ($slug !== null) {
            $context->setTenant(new Tenant(1, $slug, 'Co', '', '', '', '', TenantPlan::Pro, TenantStatus::Active));
        }

        return $context;
    }

    public function testTheRequestIdIsStampedOnTheRecord(): void
    {
        $processor = new RequestIdProcessor($this->subscriberWithAnId(), self::tenantContext(null));

        self::assertSame('req-abc', $processor(self::record())->extra['request_id'] ?? null);
    }

    public function testTheTenantIsStampedOnTheRecordWhenOneIsResolved(): void
    {
        // The point of the whole arrangement: a log line written while serving
        // a tenant says which tenant it was. The subscriber cannot say so --
        // it runs before the tenant is known -- so if this stops working,
        // nothing anywhere records it.
        $processor = new RequestIdProcessor($this->subscriberWithAnId(), self::tenantContext('acme'));

        self::assertSame('acme', $processor(self::record())->extra['tenant'] ?? null);
    }

    public function testNoTenantIsInventedWhenNoneIsResolved(): void
    {
        $processor = new RequestIdProcessor($this->subscriberWithAnId(), self::tenantContext(null));

        self::assertArrayNotHasKey('tenant', $processor(self::record())->extra);
    }

    public function testWhateverElseWasOnTheRecordSurvives(): void
    {
        // The stamps are merged in. Replacing the extra outright would throw
        // away every other processor's work, and nothing would look wrong
        // until something went missing from a log line.
        $processor = new RequestIdProcessor($this->subscriberWithAnId(), self::tenantContext('acme'));

        $extra = $processor(self::record(['memory_peak_usage' => '8 MB']))->extra;

        self::assertSame('8 MB', $extra['memory_peak_usage'] ?? null);
        self::assertSame('req-abc', $extra['request_id'] ?? null);
        self::assertSame('acme', $extra['tenant'] ?? null);
    }

    public function testARecordOutsideAnyRequestIsLeftExactlyAsItWas(): void
    {
        // Console commands log through the same handlers.
        $processor = new RequestIdProcessor(new RequestIdSubscriber(new NullLogger()), self::tenantContext(null));
        $record = self::record(['memory_peak_usage' => '8 MB']);

        self::assertSame($record, $processor($record));
    }
}
