<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Adds ETag and Cache-Control headers to API GET responses.
 * Returns 304 Not Modified when the client's If-None-Match matches.
 */
final class CacheHeaderSubscriber implements EventSubscriberInterface
{
    /** @var list<string> */
    private const CACHEABLE_PREFIXES = [
        '/api/v2/events',
        '/api/v2/categories',
        '/api/v2/users',
        '/api/v2/search',
        '/api/v2/reports',
    ];

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onResponse', -20]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();

        // Only process GET requests to cacheable API endpoints
        if ($request->getMethod() !== 'GET') {
            return;
        }

        $path = $request->getPathInfo();
        $isCacheable = false;
        foreach (self::CACHEABLE_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $isCacheable = true;
                break;
            }
        }

        if (!$isCacheable) {
            return;
        }

        // Skip non-200 responses
        if ($response->getStatusCode() !== 200) {
            return;
        }

        $content = $response->getContent();
        if ($content === false) {
            return;
        }

        // Generate ETag from response content
        $etag = '"' . md5($content) . '"';
        $response->headers->set('ETag', $etag);
        $response->headers->set('Cache-Control', 'private, max-age=0, must-revalidate');

        // Check If-None-Match
        $ifNoneMatch = $request->headers->get('If-None-Match');
        if ($ifNoneMatch !== null && $ifNoneMatch === $etag) {
            $event->setResponse(new Response('', 304, [
                'ETag' => $etag,
                'Cache-Control' => 'private, max-age=0, must-revalidate',
            ]));
        }
    }
}
