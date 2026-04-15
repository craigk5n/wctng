<?php

declare(strict_types=1);

namespace App\Controller\Api;

use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use WebCalendar\Core\Application\Contract\RateLimiterInterface;
use WebCalendar\Core\Application\Service\FeedService;
use WebCalendar\Core\Domain\Repository\UserRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\DateRange;

/**
 * Public RSS and Free/Busy feeds — no authentication required.
 *
 * Routes fall under the existing /api/v2/public/ firewall rule
 * (security: false) so no token or session is needed.
 */
final class FeedController
{
    private const RATE_LIMIT = 30;
    private const RATE_WINDOW = 60;
    private const DEFAULT_DAYS = 90;

    public function __construct(
        private readonly UserRepositoryInterface $userRepo,
        private readonly RateLimiterInterface $rateLimiter,
        // FeedService's base URL is empty at DI time; RSS/FreeBusy methods
        // override it per request.
        private readonly FeedService $feedService,
        private readonly ClockInterface $clock = new NativeClock(),
    ) {
    }

    /**
     * Test-only constructor that accepts individual dependencies.
     */
    public static function createForTest(
        UserRepositoryInterface $userRepo,
        RateLimiterInterface $rateLimiter,
        FeedService $feedService,
    ): self {
        $instance = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $setProp = static function (string $name, mixed $value) use ($instance): void {
            $ref = new \ReflectionProperty(self::class, $name);
            $ref->setValue($instance, $value);
        };
        $setProp('userRepo', $userRepo);
        $setProp('rateLimiter', $rateLimiter);
        $setProp('feedService', $feedService);
        $setProp('clock', new NativeClock());
        return $instance;
    }

    /**
     * RSS 2.0 feed of upcoming public events for a user.
     *
     * GET /api/v2/public/calendars/{username}/feed.rss?days=90
     */
    #[Route('/api/v2/public/calendars/{username}/feed.rss', name: 'api_public_feed_rss', methods: ['GET'])]
    public function rss(string $username, Request $request): Response
    {
        if (!$this->checkRateLimit($request)) {
            return new Response('Too many requests', 429);
        }

        $user = $this->userRepo->findByLogin($username);
        if ($user === null || !$this->isPublicCalendarEnabled($username)) {
            return new Response('Public calendar not found', 404);
        }

        $days = min(365, max(1, $request->query->getInt('days', self::DEFAULT_DAYS)));
        $start = $this->clock->now()->setTime(0, 0);
        $end = $start->modify("+{$days} days");
        $range = new DateRange($start, $end);

        $xml = $this->feedService->generateRss($user, $range);

        return new Response($xml, 200, [
            'Content-Type' => 'application/rss+xml; charset=utf-8',
            'Cache-Control' => 'public, max-age=900',
        ]);
    }

    /**
     * RFC 5545 VFREEBUSY feed for a user.
     *
     * GET /api/v2/public/calendars/{username}/freebusy.ifb?days=90
     */
    #[Route('/api/v2/public/calendars/{username}/freebusy.ifb', name: 'api_public_feed_freebusy', methods: ['GET'])]
    public function freeBusy(string $username, Request $request): Response
    {
        if (!$this->checkRateLimit($request)) {
            return new Response('Too many requests', 429);
        }

        $user = $this->userRepo->findByLogin($username);
        if ($user === null || !$this->isPublicCalendarEnabled($username)) {
            return new Response('Public calendar not found', 404);
        }

        $days = min(365, max(1, $request->query->getInt('days', self::DEFAULT_DAYS)));
        $start = $this->clock->now()->setTime(0, 0);
        $end = $start->modify("+{$days} days");
        $range = new DateRange($start, $end);

        $ics = $this->feedService->generateFreeBusy($user, $range);

        return new Response($ics, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Cache-Control' => 'public, max-age=900',
        ]);
    }

    private function isPublicCalendarEnabled(string $login): bool
    {
        $prefs = $this->userRepo->getPreferences($login);
        foreach ($prefs as $pref) {
            if ($pref->key() === 'public_calendar_enabled' && $pref->value() === 'Y') {
                return true;
            }
        }
        return false;
    }

    private function checkRateLimit(Request $request): bool
    {
        $ip = $request->getClientIp() ?? 'unknown';
        $identifier = 'public_feed:' . $ip;

        if (!$this->rateLimiter->isAllowed($identifier, 'public_feed', self::RATE_LIMIT, self::RATE_WINDOW)) {
            return false;
        }

        $this->rateLimiter->recordAttempt($identifier, 'public_feed', self::RATE_WINDOW);
        return true;
    }
}
