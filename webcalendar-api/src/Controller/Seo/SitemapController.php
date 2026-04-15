<?php

declare(strict_types=1);

namespace App\Controller\Seo;

use App\Service\SeoEligibilityService;
use App\Service\TenantAwarePdoProvider;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\Repository\UserRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\DateRange;

/**
 * Auto-generated sitemap.xml for search engine discovery.
 */
final class SitemapController
{
    private const MAX_URLS = 50000;

    public function __construct(
        private readonly SeoEligibilityService $seoService,
        private readonly UserRepositoryInterface $userRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly TenantAwarePdoProvider $pdoProvider,
        private readonly ClockInterface $clock = new NativeClock(),
    ) {
    }

    private const CACHE_TTL = 3600; // 1 hour

    #[Route('/sitemap.xml', name: 'seo_sitemap', methods: ['GET'])]
    public function sitemap(): Response
    {
        if (!$this->seoService->isSeoEnabledGlobally()) {
            return new Response('Not Found', 404);
        }

        // Serve from file cache if fresh (skip for SQLite/testing)
        $driver = $this->pdoProvider->get()->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $cacheFile = \dirname(__DIR__, 3) . '/var/cache/sitemap.xml';
        $useCache = $driver !== 'sqlite';

        if ($useCache && file_exists($cacheFile) && (time() - (int) filemtime($cacheFile)) < self::CACHE_TTL) {
            $cached = file_get_contents($cacheFile);
            if ($cached !== false) {
                return new Response($cached, 200, [
                    'Content-Type' => 'application/xml; charset=UTF-8',
                    'Cache-Control' => 'public, max-age=3600',
                    'X-Cache' => 'HIT',
                ]);
            }
        }

        $eligibleUsers = $this->getEligibleUsers();
        if ($eligibleUsers === []) {
            return $this->emptyResponse();
        }

        $now = $this->clock->now();
        $urls = [];

        foreach ($eligibleUsers as $login) {
            // Add public calendar SPA page
            $urls[] = $this->buildUrl(
                "/public/{$login}",
                $now->format('Y-m-d'),
                'daily',
                '0.7',
            );

            // Add event index page
            $urls[] = $this->buildUrl(
                "/public/{$login}/events",
                $now->format('Y-m-d'),
                'daily',
                '0.6',
            );

            // Add booking page
            $urls[] = $this->buildUrl(
                "/book/{$login}",
                $now->format('Y-m-d'),
                'weekly',
                '0.5',
            );

            // Fetch public events (past year + future year)
            $start = $now->modify('-1 year');
            $end = $now->modify('+1 year');
            $range = new DateRange($start, $end);
            $events = $this->eventRepository->findByDateRange($range, null, 'P', [$login]);

            foreach ($events as $event) {
                if ($event->access() !== AccessLevel::PUBLIC) {
                    continue;
                }

                $id = $event->id()->value();
                $eventStart = $event->start();
                $lastmod = $eventStart->format('Y-m-d');

                // Upcoming events get higher priority and daily changefreq
                $isUpcoming = $eventStart > $now;
                $daysAway = abs((int) $now->diff($eventStart)->format('%r%a'));

                if ($isUpcoming) {
                    $changefreq = 'daily';
                    $priority = $daysAway <= 7 ? '0.9' : ($daysAway <= 30 ? '0.8' : '0.7');
                } else {
                    $changefreq = 'monthly';
                    $priority = '0.4';
                }

                $urls[] = $this->buildUrl(
                    "/public/{$login}/event/{$id}",
                    $lastmod,
                    $changefreq,
                    $priority,
                );

                if (\count($urls) >= self::MAX_URLS) {
                    break 2;
                }
            }
        }

        $xml = $this->renderSitemap($urls);

        // Write to file cache (production only)
        if ($useCache) {
            $cacheDir = \dirname($cacheFile);
            if (!is_dir($cacheDir)) {
                @mkdir($cacheDir, 0o755, true);
            }
            @file_put_contents($cacheFile, $xml);
        }

        return new Response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
            'X-Cache' => 'MISS',
        ]);
    }

    #[Route('/robots.txt', name: 'seo_robots', methods: ['GET'])]
    public function robots(): Response
    {
        $seoEnabled = $this->seoService->isSeoEnabledGlobally();

        $lines = [
            'User-agent: *',
            '',
            '# Allow public pages',
            'Allow: /public/',
            'Allow: /book/',
            '',
            '# Disallow private areas',
            'Disallow: /api/',
            'Disallow: /admin/',
            'Disallow: /settings/',
            'Disallow: /dav/',
            'Disallow: /control/',
        ];

        if ($seoEnabled) {
            $lines[] = '';
            $lines[] = '# Sitemap';
            $lines[] = 'Sitemap: /sitemap.xml';
        }

        $lines[] = '';

        return new Response(implode("\n", $lines), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /**
     * @return list<string> Logins of users eligible for SEO indexing
     */
    private function getEligibleUsers(): array
    {
        $allUsers = $this->userRepository->findAll();
        $eligible = [];

        foreach ($allUsers as $user) {
            $status = $this->seoService->getUserSeoStatus($user->login());
            if ($status['eligible'] && !$status['noindex']) {
                $eligible[] = $user->login();
            }
        }

        return $eligible;
    }

    /**
     * @return array{loc: string, lastmod: string, changefreq: string, priority: string}
     */
    private function buildUrl(string $loc, string $lastmod, string $changefreq, string $priority): array
    {
        return [
            'loc' => $loc,
            'lastmod' => $lastmod,
            'changefreq' => $changefreq,
            'priority' => $priority,
        ];
    }

    /**
     * @param list<array{loc: string, lastmod: string, changefreq: string, priority: string}> $urls
     */
    private function renderSitemap(array $urls): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($urls as $url) {
            $loc = htmlspecialchars($url['loc'], \ENT_XML1, 'UTF-8');
            $xml .= "  <url>\n";
            $xml .= "    <loc>{$loc}</loc>\n";
            $xml .= "    <lastmod>{$url['lastmod']}</lastmod>\n";
            $xml .= "    <changefreq>{$url['changefreq']}</changefreq>\n";
            $xml .= "    <priority>{$url['priority']}</priority>\n";
            $xml .= "  </url>\n";
        }

        $xml .= '</urlset>';

        return $xml;
    }

    private function emptyResponse(): Response
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>';

        return new Response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
