<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\EventSubscriber\CacheHeaderSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

final class CacheHeaderSubscriberTest extends TestCase
{
    private function createEvent(string $method, string $path, int $status = 200, ?string $ifNoneMatch = null): ResponseEvent
    {
        $request = Request::create($path, $method);
        if ($ifNoneMatch !== null) {
            $request->headers->set('If-None-Match', $ifNoneMatch);
        }
        $response = new Response('{"data":[]}', $status, ['Content-Type' => 'application/json']);
        $kernel = $this->createMock(KernelInterface::class);

        return new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);
    }

    public function testAddsEtagToGetEvents(): void
    {
        $subscriber = new CacheHeaderSubscriber();
        $event = $this->createEvent('GET', '/api/v2/events');

        $subscriber->onResponse($event);

        $this->assertNotNull($event->getResponse()->headers->get('ETag'));
        $this->assertStringStartsWith('"', (string) $event->getResponse()->headers->get('ETag'));
    }

    public function testAddsCacheControlHeader(): void
    {
        $subscriber = new CacheHeaderSubscriber();
        $event = $this->createEvent('GET', '/api/v2/categories');

        $subscriber->onResponse($event);

        $cacheControl = $event->getResponse()->headers->get('Cache-Control');
        $this->assertNotNull($cacheControl);
        $this->assertStringContainsString('must-revalidate', $cacheControl);
    }

    public function testReturns304WhenEtagMatches(): void
    {
        $subscriber = new CacheHeaderSubscriber();

        // First request — get the ETag
        $event1 = $this->createEvent('GET', '/api/v2/events');
        $subscriber->onResponse($event1);
        $etag = $event1->getResponse()->headers->get('ETag');
        $this->assertNotNull($etag);

        // Second request with If-None-Match
        $event2 = $this->createEvent('GET', '/api/v2/events', 200, $etag);
        $subscriber->onResponse($event2);

        $this->assertSame(304, $event2->getResponse()->getStatusCode());
    }

    public function testDoesNotAddEtagToPostRequests(): void
    {
        $subscriber = new CacheHeaderSubscriber();
        $event = $this->createEvent('POST', '/api/v2/events');

        $subscriber->onResponse($event);

        $this->assertNull($event->getResponse()->headers->get('ETag'));
    }

    public function testDoesNotAddEtagToNonApiPaths(): void
    {
        $subscriber = new CacheHeaderSubscriber();
        $event = $this->createEvent('GET', '/dav/calendars');

        $subscriber->onResponse($event);

        $this->assertNull($event->getResponse()->headers->get('ETag'));
    }

    public function testDoesNotAddEtagToErrorResponses(): void
    {
        $subscriber = new CacheHeaderSubscriber();
        $event = $this->createEvent('GET', '/api/v2/events', 500);

        $subscriber->onResponse($event);

        $this->assertNull($event->getResponse()->headers->get('ETag'));
    }
}
