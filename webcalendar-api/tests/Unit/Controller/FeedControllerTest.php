<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Api\FeedController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use WebCalendar\Core\Application\Contract\RateLimiterInterface;
use WebCalendar\Core\Application\Service\EventService;
use WebCalendar\Core\Application\Service\FeedService;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\Repository\UserRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\Recurrence;
use WebCalendar\Core\Domain\ValueObject\UserPreference;

final class FeedControllerTest extends TestCase
{
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

        $this->controller = FeedController::createForTest(
            $this->userRepo,
            $this->rateLimiter,
            $feedService,
        );
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
}
