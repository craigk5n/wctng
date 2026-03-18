<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Adds Cache-Control and ETag headers to API GET responses.
 * Returns 304 Not Modified when the client's If-None-Match matches.
 *
 * Uses route-name-based rules for fine-grained cache policies:
 * - Public config endpoints: 5-minute public cache
 * - Categories: 1-minute private cache
 * - Event endpoints: ETag-based revalidation (no time-based cache)
 * - User profile: no-store (sensitive data)
 * - Non-GET: no-store
 */
final class CacheHeaderSubscriber implements EventSubscriberInterface
{
    /**
     * Route-specific cache rules. Routes not listed get no cache headers.
     *
     * @var array<string, array{cache_control: string, etag: bool}>
     */
    private const ROUTE_RULES = [
        // Public config — cacheable for 5 minutes
        'config_features' => ['cache_control' => 'public, max-age=300', 'etag' => false],
        'config_custom_html' => ['cache_control' => 'public, max-age=300', 'etag' => false],

        // Categories — private, short cache
        'api_categories_list' => ['cache_control' => 'private, max-age=60', 'etag' => false],

        // Event endpoints — ETag revalidation
        'api_events_list' => ['cache_control' => 'private, no-cache', 'etag' => true],
        'api_events_get' => ['cache_control' => 'private, no-cache', 'etag' => true],

        // Reports and search — ETag revalidation
        'api_search' => ['cache_control' => 'private, no-cache', 'etag' => true],
        'api_reports_summary' => ['cache_control' => 'private, no-cache', 'etag' => true],

        // User profile — no store
        'api_users_me' => ['cache_control' => 'private, no-store', 'etag' => false],

        // SEO pages — public, short cache
        'seo_sitemap' => ['cache_control' => 'public, max-age=3600', 'etag' => false],
        'seo_robots' => ['cache_control' => 'public, max-age=86400', 'etag' => false],
    ];

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onResponse', -20]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();

        // Non-GET requests: no-store
        if ($request->getMethod() !== 'GET') {
            $response->headers->set('Cache-Control', 'no-store');
            return;
        }

        $routeName = $request->attributes->getString('_route');
        if ($routeName === '') {
            return;
        }

        $rule = self::ROUTE_RULES[$routeName] ?? null;
        if ($rule === null) {
            return;
        }

        $response->headers->set('Cache-Control', $rule['cache_control']);

        // ETag-based conditional responses
        if ($rule['etag'] && $response->getStatusCode() === 200) {
            $content = $response->getContent();
            if ($content !== false) {
                $etag = '"' . md5($content) . '"';
                $response->headers->set('ETag', $etag);

                $ifNoneMatch = $request->headers->get('If-None-Match');
                if ($ifNoneMatch !== null && $ifNoneMatch === $etag) {
                    $event->setResponse(new Response('', 304, [
                        'ETag' => $etag,
                        'Cache-Control' => $rule['cache_control'],
                    ]));
                }
            }
        }
    }
}
