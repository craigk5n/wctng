<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Api\FeedController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use WebCalendar\Core\Application\Contract\RateLimiterInterface;
use WebCalendar\Core\Application\Service\EventService;
use WebCalendar\Core\Application\Service\FeedService;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\Repository\UserRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\Recurrence;
use WebCalendar\Core\Domain\ValueObject\UserPreference;

final class FeedControllerTest extends TestCase
{
    private const string NOW = '2026-06-15T12:00:00+00:00';

    private UserRepositoryInterface&MockObject $userRepo;
    private EventRepositoryInterface&MockObject $eventRepo;
    private RateLimiterInterface&MockObject $rateLimiter;
    private FeedController $controller;

    protected function setUp(): void
    {
        $this->userRepo = $this->createMock(UserRepositoryInterface::class);
        $this->eventRepo = $this->createMock(EventRepositoryInterface::class);
        $this->rateLimiter = $this->createMock(RateLimiterInterface::class);

        $eventService = new EventService($this->eventRepo, $this->userRepo);
        $feedService = new FeedService($eventService, 'https://example.com');

        // The real constructor rather than createForTest(), which takes the
        // same three dependencies and then hardcodes a NativeClock -- with a
        // wall clock in place the feed window cannot be asserted at all.
        $this->controller = new FeedController(
            $this->userRepo,
            $this->rateLimiter,
            $feedService,
            new MockClock(self::NOW),
        );
    }

    private ?DateRange $seenRange = null;

    /** Arms the repository so the range the feed is generated over is recorded. */
    private function recordRange(): void
    {
        $this->eventRepo->method('findByDateRange')
            ->willReturnCallback(function (DateRange $range): array {
                $this->seenRange ??= $range;

                return [];
            });
    }

    /** @return array{string, string} the window's first and last instant */
    private function windowFor(string $endpoint, string $query = ''): array
    {
        $this->allowRateLimit();
        $this->enablePublicCalendar('alice');
        $this->recordRange();

        $path = '/api/v2/public/calendars/alice/' . ($endpoint === 'rss' ? 'feed.rss' : 'freebusy.ifb');
        $this->controller->{$endpoint}('alice', Request::create($path . $query));

        self::assertNotNull($this->seenRange, 'the feed never queried a range');

        return [
            $this->seenRange->startDate()->format('Y-m-d H:i:s'),
            $this->seenRange->endDate()->format('Y-m-d H:i:s'),
        ];
    }

    private function makeUser(string $login): User
    {
        return new User($login, ucfirst($login), 'Smith', $login . '@example.com', false, true);
    }

    private function makeEvent(int $id, string $createdBy): Event
    {
        return new Event(
            id: new EventId($id),
            uid: "event-{$id}@example.com",
            name: "Test Event {$id}",
            description: 'A test event',
            location: 'Office',
            start: new \DateTimeImmutable('+1 day 10:00:00'),
            duration: 60,
            createdBy: $createdBy,
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
            recurrence: new Recurrence(),
        );
    }

    private function allowRateLimit(): void
    {
        $this->rateLimiter->method('isAllowed')->willReturn(true);
    }

    private function enablePublicCalendar(string $login): void
    {
        $this->userRepo->method('findByLogin')
            ->with($login)
            ->willReturn($this->makeUser($login));
        $this->userRepo->method('getPreferences')
            ->with($login)
            ->willReturn([new UserPreference('public_calendar_enabled', 'Y')]);
    }

    // --- RSS ---

    public function testRssReturns429WhenRateLimited(): void
    {
        $this->rateLimiter->method('isAllowed')->willReturn(false);

        $request = Request::create('/api/v2/public/calendars/alice/feed.rss');
        $response = $this->controller->rss('alice', $request);

        $this->assertSame(429, $response->getStatusCode());
    }

    public function testRssReturns404ForUnknownUser(): void
    {
        $this->allowRateLimit();
        $this->userRepo->method('findByLogin')->willReturn(null);

        $request = Request::create('/api/v2/public/calendars/nobody/feed.rss');
        $response = $this->controller->rss('nobody', $request);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testRssReturns404WhenPublicCalendarDisabled(): void
    {
        $this->allowRateLimit();
        $this->userRepo->method('findByLogin')->willReturn($this->makeUser('alice'));
        $this->userRepo->method('getPreferences')->willReturn([]);

        $request = Request::create('/api/v2/public/calendars/alice/feed.rss');
        $response = $this->controller->rss('alice', $request);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testRssReturnsValidXml(): void
    {
        $this->allowRateLimit();
        $this->enablePublicCalendar('alice');
        $this->eventRepo->method('findByDateRange')->willReturn([
            $this->makeEvent(1, 'alice'),
        ]);

        $request = Request::create('/api/v2/public/calendars/alice/feed.rss');
        $response = $this->controller->rss('alice', $request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('application/rss+xml', $response->headers->get('Content-Type', ''));
        $this->assertStringContainsString('max-age=900', $response->headers->get('Cache-Control', ''));

        $body = $response->getContent();
        $this->assertIsString($body);
        $this->assertStringContainsString('<rss', $body);
        $this->assertStringContainsString('<channel>', $body);
        $this->assertStringContainsString('<item>', $body);
        $this->assertStringContainsString('Test Event 1', $body);
    }

    public function testRssEmptyCalendar(): void
    {
        $this->allowRateLimit();
        $this->enablePublicCalendar('alice');
        $this->eventRepo->method('findByDateRange')->willReturn([]);

        $request = Request::create('/api/v2/public/calendars/alice/feed.rss');
        $response = $this->controller->rss('alice', $request);

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getContent();
        $this->assertIsString($body);
        $this->assertStringContainsString('<channel>', $body);
        $this->assertStringNotContainsString('<item>', $body);
    }

    // --- FreeBusy ---

    public function testFreeBusyReturns429WhenRateLimited(): void
    {
        $this->rateLimiter->method('isAllowed')->willReturn(false);

        $request = Request::create('/api/v2/public/calendars/alice/freebusy.ifb');
        $response = $this->controller->freeBusy('alice', $request);

        $this->assertSame(429, $response->getStatusCode());
    }

    public function testFreeBusyReturns404ForUnknownUser(): void
    {
        $this->allowRateLimit();
        $this->userRepo->method('findByLogin')->willReturn(null);

        $request = Request::create('/api/v2/public/calendars/nobody/freebusy.ifb');
        $response = $this->controller->freeBusy('nobody', $request);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testFreeBusyReturnsValidIcs(): void
    {
        $this->allowRateLimit();
        $this->enablePublicCalendar('alice');
        $this->eventRepo->method('findByDateRange')->willReturn([
            $this->makeEvent(1, 'alice'),
        ]);

        $request = Request::create('/api/v2/public/calendars/alice/freebusy.ifb');
        $response = $this->controller->freeBusy('alice', $request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('text/calendar', $response->headers->get('Content-Type', ''));

        $body = $response->getContent();
        $this->assertIsString($body);
        $this->assertStringContainsString('BEGIN:VCALENDAR', $body);
        $this->assertStringContainsString('BEGIN:VFREEBUSY', $body);
        $this->assertStringContainsString('FREEBUSY', $body);
    }

    public function testRssDaysParameterClamped(): void
    {
        $this->allowRateLimit();
        $this->enablePublicCalendar('alice');
        $this->eventRepo->method('findByDateRange')->willReturn([]);

        // days > 365 should be clamped to 365
        $request = Request::create('/api/v2/public/calendars/alice/feed.rss?days=999');
        $response = $this->controller->rss('alice', $request);
        $this->assertSame(200, $response->getStatusCode());
    }

    // ------------------------------------------------------- the feed window

    /** @return iterable<string, array{string}> */
    public static function endpoints(): iterable
    {
        yield 'rss' => ['rss'];
        yield 'freeBusy' => ['freeBusy'];
    }

    #[DataProvider('endpoints')]
    public function testTheWindowStartsAtMidnightToday(string $endpoint): void
    {
        // setTime(0, 0) on the injected clock. Without it the window starts at
        // whatever time of day the request arrived, so two callers a minute
        // apart get different feeds and neither is cacheable.
        [$start] = $this->windowFor($endpoint);

        self::assertSame('2026-06-15 00:00:00', $start);
    }

    #[DataProvider('endpoints')]
    public function testTheWindowIsNinetyDaysByDefault(string $endpoint): void
    {
        [$start, $end] = $this->windowFor($endpoint);

        self::assertSame('2026-06-15 00:00:00', $start);
        self::assertSame('2026-09-13 00:00:00', $end);
    }

    #[DataProvider('endpoints')]
    public function testTheWindowHonoursTheDaysParameter(string $endpoint): void
    {
        [, $end] = $this->windowFor($endpoint, '?days=7');

        self::assertSame('2026-06-22 00:00:00', $end);
    }

    #[DataProvider('endpoints')]
    public function testTheWindowIsCappedAtAYear(string $endpoint): void
    {
        // A feed is generated per request with no pagination, so an unbounded
        // days parameter is an invitation to walk the whole table.
        [, $end] = $this->windowFor($endpoint, '?days=9999');

        self::assertSame('2027-06-15 00:00:00', $end);
    }

    #[DataProvider('endpoints')]
    public function testTheWindowIsAtLeastOneDay(string $endpoint): void
    {
        // days=0 or a negative would otherwise make end <= start, which is an
        // empty or inverted range rather than a small one.
        [$start, $end] = $this->windowFor($endpoint, '?days=0');

        self::assertSame('2026-06-15 00:00:00', $start);
        self::assertSame('2026-06-16 00:00:00', $end);
    }

    #[DataProvider('endpoints')]
    public function testANegativeDaysParameterDoesNotInvertTheWindow(string $endpoint): void
    {
        [$start, $end] = $this->windowFor($endpoint, '?days=-30');

        self::assertSame('2026-06-15 00:00:00', $start);
        self::assertSame('2026-06-16 00:00:00', $end);
    }

    // ----------------------------------------------------------- the guards

    public function testFreeBusyIsAlsoRefusedWhenTheCalendarIsNotPublic(): void
    {
        // The rss endpoint had this; freeBusy did not, so an `&&` in place of
        // its `||` served a private calendar's busy times to anyone.
        $this->allowRateLimit();
        $this->userRepo->method('findByLogin')->willReturn($this->makeUser('alice'));
        $this->userRepo->method('getPreferences')->willReturn([]);

        $response = $this->controller->freeBusy('alice', Request::create('/api/v2/public/calendars/alice/freebusy.ifb'));

        self::assertSame(404, $response->getStatusCode());
    }

    #[DataProvider('endpoints')]
    public function testAnotherPreferenceSetToYesDoesNotPublishACalendar(string $endpoint): void
    {
        // Both the key and the value have to match; either alone must not do.
        $this->allowRateLimit();
        $this->userRepo->method('findByLogin')->willReturn($this->makeUser('alice'));
        $this->userRepo->method('getPreferences')->willReturn([
            new UserPreference('some_other_flag', 'Y'),
            new UserPreference('public_calendar_enabled', 'N'),
        ]);

        $path = '/api/v2/public/calendars/alice/' . ($endpoint === 'rss' ? 'feed.rss' : 'freebusy.ifb');
        $response = $this->controller->{$endpoint}('alice', Request::create($path));

        self::assertSame(404, $response->getStatusCode());
    }

    // ------------------------------------------------------- rate limiting

    #[DataProvider('endpoints')]
    public function testTheRateLimitIsKeyedOnTheClientAddress(string $endpoint): void
    {
        $this->rateLimiter->expects($this->once())->method('isAllowed')
            ->with('public_feed:203.0.113.9', 'public_feed', 30, 60)
            ->willReturn(true);
        $this->rateLimiter->expects($this->once())->method('recordAttempt')
            ->with('public_feed:203.0.113.9', 'public_feed', 60);
        $this->enablePublicCalendar('alice');
        $this->recordRange();

        $path = '/api/v2/public/calendars/alice/' . ($endpoint === 'rss' ? 'feed.rss' : 'freebusy.ifb');
        $request = Request::create($path, 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.9']);

        self::assertSame(200, $this->controller->{$endpoint}('alice', $request)->getStatusCode());
    }

    public function testARequestWithNoClientAddressStillGetsAKey(): void
    {
        $this->rateLimiter->expects($this->once())->method('isAllowed')
            ->with('public_feed:unknown', 'public_feed', 30, 60)
            ->willReturn(false);

        // A bare Request has no REMOTE_ADDR, so getClientIp() is null.
        self::assertSame(429, $this->controller->rss('alice', new Request())->getStatusCode());
    }

    #[DataProvider('endpoints')]
    public function testARejectedRequestIsNotAlsoRecordedAsAnAttempt(string $endpoint): void
    {
        $this->rateLimiter->method('isAllowed')->willReturn(false);
        $this->rateLimiter->expects($this->never())->method('recordAttempt');

        $path = '/api/v2/public/calendars/alice/' . ($endpoint === 'rss' ? 'feed.rss' : 'freebusy.ifb');

        self::assertSame(429, $this->controller->{$endpoint}('alice', Request::create($path))->getStatusCode());
    }
}
