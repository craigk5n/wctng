<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\EventSubscriber\RequestIdSubscriber;
use App\Monolog\RequestIdProcessor;
use App\Tenant\TenantContext;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

final class RequestIdProcessorTest extends TestCase
{
    public function testAddsRequestIdToLogRecord(): void
    {
        $subscriber = $this->createRequestIdSubscriberWithRequest();
        $processor = new RequestIdProcessor($subscriber);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'Test log',
        );

        $processed = $processor($record);
        $this->assertArrayHasKey('request_id', $processed->extra);
        $this->assertNotEmpty($processed->extra['request_id']);
    }

    public function testDoesNotAddRequestIdWhenNoRequest(): void
    {
        $subscriber = new RequestIdSubscriber(new NullLogger(), new TenantContext());
        $processor = new RequestIdProcessor($subscriber);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'Test log',
        );

        $processed = $processor($record);
        $this->assertArrayNotHasKey('request_id', $processed->extra);
    }

    private function createRequestIdSubscriberWithRequest(): RequestIdSubscriber
    {
        $subscriber = new RequestIdSubscriber(new NullLogger(), new TenantContext());

        // Simulate a request to set the request ID
        $request = Request::create('/api/v2/events');
        $kernel = $this->createMock(KernelInterface::class);
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onRequest($event);

        return $subscriber;
    }
}
