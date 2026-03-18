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
    private function createEvent(string $method, string $path, string $routeName = '', int $status = 200, ?string $ifNoneMatch = null): ResponseEvent
    {
        $request = Request::create($path, $method);
        if ($routeName !== '') {
            $request->attributes->set('_route', $routeName);
        }
        if ($ifNoneMatch !== null) {
            $request->headers->set('If-None-Match', $ifNoneMatch);
        }
        $response = new Response('{"data":[]}', $status, ['Content-Type' => 'application/json']);
        $kernel = $this->createMock(KernelInterface::class);

        return new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);
    }

    private function assertCacheControl(Response $response, string ...$directives): void
    {
        $cc = (string) $response->headers->get('Cache-Control');
        foreach ($directives as $directive) {
            $this->assertStringContainsString($directive, $cc, "Cache-Control missing '{$directive}': got '{$cc}'");
        }
    }

    public function testAddsEtagToGetEvents(): void
    {
        $subscriber = new CacheHeaderSubscriber();
        $event = $this->createEvent('GET', '/api/v2/events', 'api_events_list');

        $subscriber->onResponse($event);

        $this->assertNotNull($event->getResponse()->headers->get('ETag'));
        $this->assertStringStartsWith('"', (string) $event->getResponse()->headers->get('ETag'));
        $this->assertCacheControl($event->getResponse(), 'private', 'no-cache');
    }

    public function testEventGetHasEtagAndPrivateNoCache(): void
    {
        $subscriber = new CacheHeaderSubscriber();
        $event = $this->createEvent('GET', '/api/v2/events/42', 'api_events_get');

        $subscriber->onResponse($event);

        $this->assertNotNull($event->getResponse()->headers->get('ETag'));
        $this->assertCacheControl($event->getResponse(), 'private', 'no-cache');
    }

    public function testConfigFeaturesIsPublicCached(): void
    {
        $subscriber = new CacheHeaderSubscriber();
        $event = $this->createEvent('GET', '/api/v2/config/features', 'config_features');

        $subscriber->onResponse($event);

        $this->assertCacheControl($event->getResponse(), 'public', 'max-age=300');
        $this->assertNull($event->getResponse()->headers->get('ETag'));
    }

    public function testCustomHtmlIsPublicCached(): void
    {
        $subscriber = new CacheHeaderSubscriber();
        $event = $this->createEvent('GET', '/api/v2/config/custom-html', 'config_custom_html');

        $subscriber->onResponse($event);

        $this->assertCacheControl($event->getResponse(), 'public', 'max-age=300');
    }

    public function testCategoriesHaveShortPrivateCache(): void
    {
        $subscriber = new CacheHeaderSubscriber();
        $event = $this->createEvent('GET', '/api/v2/categories', 'api_categories_list');

        $subscriber->onResponse($event);

        $this->assertCacheControl($event->getResponse(), 'private', 'max-age=60');
    }

    public function testUsersMeIsNoStore(): void
    {
        $subscriber = new CacheHeaderSubscriber();
        $event = $this->createEvent('GET', '/api/v2/users/me', 'api_users_me');

        $subscriber->onResponse($event);

        $this->assertCacheControl($event->getResponse(), 'private', 'no-store');
    }

    public function testReturns304WhenEtagMatches(): void
    {
        $subscriber = new CacheHeaderSubscriber();

        // First request — get the ETag
        $event1 = $this->createEvent('GET', '/api/v2/events', 'api_events_list');
        $subscriber->onResponse($event1);
        $etag = $event1->getResponse()->headers->get('ETag');
        $this->assertNotNull($etag);

        // Second request with If-None-Match
        $event2 = $this->createEvent('GET', '/api/v2/events', 'api_events_list', 200, $etag);
        $subscriber->onResponse($event2);

        $this->assertSame(304, $event2->getResponse()->getStatusCode());
    }

    public function testPostRequestsGetNoStore(): void
    {
        $subscriber = new CacheHeaderSubscriber();
        $event = $this->createEvent('POST', '/api/v2/events', 'api_events_create');

        $subscriber->onResponse($event);

        $this->assertCacheControl($event->getResponse(), 'no-store');
        $this->assertNull($event->getResponse()->headers->get('ETag'));
    }

    public function testUnknownRouteGetsNoHeaders(): void
    {
        $subscriber = new CacheHeaderSubscriber();
        $event = $this->createEvent('GET', '/dav/calendars', 'caldav_root');

        $subscriber->onResponse($event);

        $this->assertNull($event->getResponse()->headers->get('ETag'));
    }

    public function testDoesNotAddEtagToErrorResponses(): void
    {
        $subscriber = new CacheHeaderSubscriber();
        $event = $this->createEvent('GET', '/api/v2/events', 'api_events_list', 500);

        $subscriber->onResponse($event);

        $this->assertNull($event->getResponse()->headers->get('ETag'));
    }
}
