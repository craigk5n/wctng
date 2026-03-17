<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Api\PublicCalendarController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use WebCalendar\Core\Application\Contract\RateLimiterInterface;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\Repository\UserRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\Recurrence;
use WebCalendar\Core\Domain\ValueObject\UserPreference;

final class PublicCalendarControllerTest extends TestCase
{
    private EventRepositoryInterface&MockObject $eventRepo;
    private UserRepositoryInterface&MockObject $userRepo;
    private RateLimiterInterface&MockObject $rateLimiter;
    private PublicCalendarController $controller;

    protected function setUp(): void
    {
        $this->eventRepo = $this->createMock(EventRepositoryInterface::class);
        $this->userRepo = $this->createMock(UserRepositoryInterface::class);
        $this->rateLimiter = $this->createMock(RateLimiterInterface::class);

        $this->controller = PublicCalendarController::createForTest(
            $this->eventRepo,
            $this->userRepo,
            $this->rateLimiter,
        );
    }

    private function makeUser(string $login, bool $admin = false): User
    {
        return new User($login, ucfirst($login), 'Smith', $login . '@example.com', $admin, true);
    }

    private function makeEvent(int $id, string $createdBy, AccessLevel $access = AccessLevel::PUBLIC): Event
    {
        return new Event(
            id: new EventId($id),
            uid: "event-{$id}@example.com",
            name: "Test Event {$id}",
            description: 'A test event',
            location: 'Office',
            start: new \DateTimeImmutable('2026-04-01 10:00:00'),
            duration: 60,
            createdBy: $createdBy,
            type: EventType::EVENT,
            access: $access,
            recurrence: new Recurrence(),
        );
    }

    private function allowRateLimit(): void
    {
        $this->rateLimiter->method('isAllowed')->willReturn(true);
    }

    // --- listPublicCalendars ---

    public function testListPublicCalendarsRateLimited(): void
    {
        $this->rateLimiter->method('isAllowed')->willReturn(false);

        $request = Request::create('/api/v2/public/calendars');
        $response = $this->controller->listPublicCalendars($request);

        $this->assertSame(429, $response->getStatusCode());
    }

    public function testListPublicCalendarsReturnsUsersWithPublicEnabled(): void
    {
        $this->allowRateLimit();

        $user1 = $this->makeUser('alice');
        $user2 = $this->makeUser('bob');
        $this->userRepo->method('findAll')->willReturn([$user1, $user2]);

        $this->userRepo->method('getPreferences')
            ->willReturnCallback(function (string $login): array {
                if ($login === 'alice') {
                    return [new UserPreference('public_calendar_enabled', 'Y')];
                }
                return [];
            });

        $request = Request::create('/api/v2/public/calendars');
        $response = $this->controller->listPublicCalendars($request);

        $this->assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true);
        $this->assertNotNull($body['data']);
        $this->assertCount(1, $body['data']);
        $this->assertSame('alice', $body['data'][0]['username']);
        $this->assertSame('Alice Smith', $body['data'][0]['display_name']);
    }

    public function testListPublicCalendarsReturnsEmptyWhenNoneEnabled(): void
    {
        $this->allowRateLimit();

        $user1 = $this->makeUser('alice');
        $this->userRepo->method('findAll')->willReturn([$user1]);
        $this->userRepo->method('getPreferences')->willReturn([]);

        $request = Request::create('/api/v2/public/calendars');
        $response = $this->controller->listPublicCalendars($request);

        $this->assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true);
        $this->assertCount(0, $body['data']);
    }

    // --- listPublicEvents ---

    public function testListPublicEventsRequiresStartEnd(): void
    {
        $this->allowRateLimit();

        $user = $this->makeUser('alice');
        $this->userRepo->method('findByLogin')->willReturn($user);
        $this->userRepo->method('getPreferences')
            ->willReturn([new UserPreference('public_calendar_enabled', 'Y')]);

        $request = Request::create('/api/v2/public/calendars/alice/events');
        $response = $this->controller->listPublicEvents('alice', $request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testListPublicEventsReturns404WhenUserNotFound(): void
    {
        $this->allowRateLimit();
        $this->userRepo->method('findByLogin')->willReturn(null);

        $request = Request::create('/api/v2/public/calendars/nobody/events?start=20260401&end=20260430');
        $response = $this->controller->listPublicEvents('nobody', $request);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testListPublicEventsReturns404WhenNotPublicEnabled(): void
    {
        $this->allowRateLimit();

        $user = $this->makeUser('bob');
        $this->userRepo->method('findByLogin')->willReturn($user);
        $this->userRepo->method('getPreferences')->willReturn([]);

        $request = Request::create('/api/v2/public/calendars/bob/events?start=20260401&end=20260430');
        $response = $this->controller->listPublicEvents('bob', $request);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testListPublicEventsReturnsPublicAccessEvents(): void
    {
        $this->allowRateLimit();

        $user = $this->makeUser('alice');
        $this->userRepo->method('findByLogin')->willReturn($user);
        $this->userRepo->method('getPreferences')
            ->willReturn([new UserPreference('public_calendar_enabled', 'Y')]);

        $publicEvent1 = $this->makeEvent(1, 'alice', AccessLevel::PUBLIC);
        $publicEvent2 = $this->makeEvent(4, 'alice', AccessLevel::PUBLIC);

        // Repository returns only public events (filtered by accessLevel='P')
        $this->eventRepo->method('findByDateRange')->willReturn([$publicEvent1, $publicEvent2]);

        $request = Request::create('/api/v2/public/calendars/alice/events?start=20260401&end=20260430');
        $response = $this->controller->listPublicEvents('alice', $request);

        $this->assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true);
        $this->assertCount(2, $body['data']);
        $this->assertSame(1, $body['data'][0]['id']);
        $this->assertSame(4, $body['data'][1]['id']);
    }

    public function testListPublicEventsPaginates(): void
    {
        $this->allowRateLimit();

        $user = $this->makeUser('alice');
        $this->userRepo->method('findByLogin')->willReturn($user);
        $this->userRepo->method('getPreferences')
            ->willReturn([new UserPreference('public_calendar_enabled', 'Y')]);

        $events = [];
        for ($i = 1; $i <= 5; $i++) {
            $events[] = $this->makeEvent($i, 'alice', AccessLevel::PUBLIC);
        }
        $this->eventRepo->method('findByDateRange')->willReturn($events);

        $request = Request::create('/api/v2/public/calendars/alice/events?start=20260401&end=20260430&page=2&limit=2');
        $response = $this->controller->listPublicEvents('alice', $request);

        $this->assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true);
        $this->assertCount(2, $body['data']);
        $this->assertSame(5, $body['meta']['total']);
        $this->assertSame(2, $body['meta']['page']);
    }

    public function testListPublicEventsRateLimited(): void
    {
        $this->rateLimiter->method('isAllowed')->willReturn(false);

        $request = Request::create('/api/v2/public/calendars/alice/events?start=20260401&end=20260430');
        $response = $this->controller->listPublicEvents('alice', $request);

        $this->assertSame(429, $response->getStatusCode());
    }

    // --- togglePublicCalendar (admin endpoint) ---

    public function testTogglePublicCalendarSetsPreference(): void
    {
        $target = $this->makeUser('alice');
        $admin = $this->makeUser('admin', true);

        $this->userRepo->method('findByLogin')->willReturn($target);

        $this->userRepo->expects($this->once())
            ->method('savePreference')
            ->with('alice', $this->callback(function (UserPreference $pref): bool {
                return $pref->key() === 'public_calendar_enabled' && $pref->value() === 'Y';
            }));

        $request = Request::create(
            '/api/v2/admin/users/alice/public-calendar',
            'PUT',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['enabled' => true], \JSON_THROW_ON_ERROR),
        );
        $response = $this->controller->togglePublicCalendar('alice', $request, $admin);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testTogglePublicCalendarDisables(): void
    {
        $target = $this->makeUser('alice');
        $admin = $this->makeUser('admin', true);

        $this->userRepo->method('findByLogin')->willReturn($target);

        $this->userRepo->expects($this->once())
            ->method('savePreference')
            ->with('alice', $this->callback(function (UserPreference $pref): bool {
                return $pref->key() === 'public_calendar_enabled' && $pref->value() === 'N';
            }));

        $request = Request::create(
            '/api/v2/admin/users/alice/public-calendar',
            'PUT',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['enabled' => false], \JSON_THROW_ON_ERROR),
        );
        $response = $this->controller->togglePublicCalendar('alice', $request, $admin);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testTogglePublicCalendarReturns404ForUnknownUser(): void
    {
        $admin = $this->makeUser('admin', true);

        $this->userRepo->method('findByLogin')->willReturn(null);

        $request = Request::create(
            '/api/v2/admin/users/nobody/public-calendar',
            'PUT',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['enabled' => true], \JSON_THROW_ON_ERROR),
        );
        $response = $this->controller->togglePublicCalendar('nobody', $request, $admin);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testTogglePublicCalendarRequiresAdmin(): void
    {
        $nonAdmin = $this->makeUser('alice', false);

        $target = $this->makeUser('bob');
        $this->userRepo->method('findByLogin')->willReturn($target);

        $request = Request::create(
            '/api/v2/admin/users/bob/public-calendar',
            'PUT',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['enabled' => true], \JSON_THROW_ON_ERROR),
        );
        $response = $this->controller->togglePublicCalendar('bob', $request, $nonAdmin);

        $this->assertSame(403, $response->getStatusCode());
    }
}
