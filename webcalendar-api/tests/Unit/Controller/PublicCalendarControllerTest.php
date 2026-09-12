<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Api\PublicCalendarController;
use PHPUnit\Framework\Attributes\DataProvider;
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

    private function makeEvent(
        int $id,
        string $createdBy,
        AccessLevel $access = AccessLevel::PUBLIC,
        ?string $status = null,
    ): Event {
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
            status: $status,
        );
    }

    /**
     * Access 'P' is the only thing the query filters on, so an entry waiting
     * for an administrator, one they refused, and one its owner deleted --
     * DeleteEventController soft-deletes by writing 'cancelled' -- all came
     * back from it and all reached this list.
     */
    public function testListPublicEventsLeavesOutWhatWasNeverPublished(): void
    {
        $this->allowRateLimit();

        $this->userRepo->method('findByLogin')->willReturn($this->makeUser('alice'));
        $this->userRepo->method('getPreferences')
            ->willReturn([new UserPreference('public_calendar_enabled', 'Y')]);

        $this->eventRepo->method('findByDateRange')->willReturn([
            $this->makeEvent(1, 'alice', AccessLevel::PUBLIC, 'needs_approval'),
            $this->makeEvent(2, 'alice', AccessLevel::PUBLIC, 'confirmed'),
            $this->makeEvent(3, 'alice', AccessLevel::PUBLIC, 'cancelled'),
            $this->makeEvent(4, 'alice', AccessLevel::PUBLIC, 'rejected'),
            $this->makeEvent(5, 'alice', AccessLevel::PUBLIC),
        ]);

        $request = Request::create('/api/v2/public/calendars/alice/events?start=20260401&end=20260430');
        $response = $this->controller->listPublicEvents('alice', $request);

        /** @var array{data: list<array{id: int}>, meta: array{total: int}} $body */
        $body = json_decode((string) $response->getContent(), true);

        $this->assertSame([2, 5], array_column($body['data'], 'id'));
        $this->assertSame(2, $body['meta']['total'], 'the count has to match what is listed');
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

    /**
     * createFromFormat rolled an impossible date forward rather than refusing
     * it, so this listing answered for days nobody asked about -- and a range
     * the wrong way round reached DateRange, which refuses it, uncaught.
     */
    #[DataProvider('rangesThisListingCannotAnswer')]
    public function testListPublicEventsRefusesARangeItCannotAnswer(string $query): void
    {
        $this->allowRateLimit();
        $this->userRepo->method('findByLogin')->willReturn($this->makeUser('alice'));
        $this->userRepo->method('getPreferences')
            ->willReturn([new UserPreference('public_calendar_enabled', 'Y')]);

        $request = Request::create('/api/v2/public/calendars/alice/events?' . $query);
        $response = $this->controller->listPublicEvents('alice', $request);

        $this->assertSame(400, $response->getStatusCode());
    }

    /** @return iterable<string, array{string}> */
    public static function rangesThisListingCannotAnswer(): iterable
    {
        yield 'the 31st of February' => ['start=20260231&end=20260401'];
        yield 'the 13th month' => ['start=20260401&end=20261345'];
        yield 'the 32nd' => ['start=20260132&end=20260401'];
        yield 'ends before it starts' => ['start=20260430&end=20260401'];
    }

    public function testListPublicEventsAnswersForASingleDay(): void
    {
        $this->allowRateLimit();
        $this->userRepo->method('findByLogin')->willReturn($this->makeUser('alice'));
        $this->userRepo->method('getPreferences')
            ->willReturn([new UserPreference('public_calendar_enabled', 'Y')]);
        $this->eventRepo->method('findByDateRange')->willReturn([]);

        $request = Request::create('/api/v2/public/calendars/alice/events?start=20260401&end=20260401');

        $this->assertSame(200, $this->controller->listPublicEvents('alice', $request)->getStatusCode());
    }

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

    // --- query parameter handling ---

    /**
     * Makes alice a public calendar with the given events.
     *
     * @param list<\WebCalendar\Core\Domain\Entity\Event> $events
     */
    private function publicAlice(array $events = []): void
    {
        $this->allowRateLimit();
        $this->userRepo->method('findByLogin')->willReturn($this->makeUser('alice'));
        $this->userRepo->method('getPreferences')
            ->willReturn([new UserPreference('public_calendar_enabled', 'Y')]);
        $this->eventRepo->method('findByDateRange')->willReturn($events);
    }

    /** @return iterable<string, array{string}> */
    public static function incompleteRanges(): iterable
    {
        yield 'neither' => [''];
        yield 'only start' => ['?start=20260401'];
        yield 'only end' => ['?end=20260430'];
    }

    #[DataProvider('incompleteRanges')]
    public function testBothEndsOfTheRangeAreRequired(string $query): void
    {
        // The message matters, not just the 400. A half-supplied range is
        // rejected twice over -- once for being absent and again for being
        // unparseable -- so asserting only the status cannot tell which guard
        // fired, and an `&&` in place of the `||` here still yields a 400
        // from the date check further down.
        $this->publicAlice();

        $response = $this->controller->listPublicEvents(
            'alice',
            Request::create('/api/v2/public/calendars/alice/events' . $query),
        );

        $this->assertSame(400, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true);
        $this->assertStringContainsString('Missing required query params', (string) $body['error']['message']);
    }

    /** @return iterable<string, array{string}> */
    public static function unparseableRanges(): iterable
    {
        yield 'start is not a date' => ['?start=notadate&end=20260430'];
        yield 'end is not a date' => ['?start=20260401&end=notadate'];
    }

    #[DataProvider('unparseableRanges')]
    public function testEitherEndBeingUnparseableIsRejected(string $query): void
    {
        $this->publicAlice();

        $response = $this->controller->listPublicEvents(
            'alice',
            Request::create('/api/v2/public/calendars/alice/events' . $query),
        );

        $this->assertSame(400, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true);
        $this->assertStringContainsString('Invalid date format', (string) $body['error']['message']);
    }

    public function testTheRangeStartsAtMidnightRatherThanTheCurrentTime(): void
    {
        // createFromFormat('Ymd', ...) fills the time from the clock, so
        // without setTime(0, 0) the window would start at whatever time of day
        // the request happened to arrive.
        $this->allowRateLimit();
        $this->userRepo->method('findByLogin')->willReturn($this->makeUser('alice'));
        $this->userRepo->method('getPreferences')
            ->willReturn([new UserPreference('public_calendar_enabled', 'Y')]);

        $seen = null;
        $this->eventRepo->expects($this->once())->method('findByDateRange')
            ->willReturnCallback(static function (mixed $range) use (&$seen): array {
                $seen = $range;

                return [];
            });

        $this->controller->listPublicEvents(
            'alice',
            Request::create('/api/v2/public/calendars/alice/events?start=20260401&end=20260430'),
        );

        $this->assertInstanceOf(\WebCalendar\Core\Domain\ValueObject\DateRange::class, $seen);
        $this->assertSame('20260401 00:00:00', $seen->startDate()->format('Ymd H:i:s'));
        $this->assertSame('20260430 00:00:00', $seen->endDate()->format('Ymd H:i:s'));
    }

    public function testOnlyTheNamedCalendarsEventsAreQueried(): void
    {
        // The owner filter is the whole of the access control here: without it
        // the query widens to every user's public events.
        $this->allowRateLimit();
        $this->userRepo->method('findByLogin')->willReturn($this->makeUser('alice'));
        $this->userRepo->method('getPreferences')
            ->willReturn([new UserPreference('public_calendar_enabled', 'Y')]);

        $this->eventRepo->expects($this->once())->method('findByDateRange')
            ->with($this->anything(), null, 'P', ['alice'])
            ->willReturn([]);

        $this->controller->listPublicEvents(
            'alice',
            Request::create('/api/v2/public/calendars/alice/events?start=20260401&end=20260430'),
        );
    }

    // --- paging bounds ---

    /** @return list<\WebCalendar\Core\Domain\Entity\Event> */
    private function events(int $count): array
    {
        $events = [];

        for ($i = 1; $i <= $count; $i++) {
            $events[] = $this->makeEvent($i, 'alice', AccessLevel::PUBLIC);
        }

        return $events;
    }

    /** @return array<string, mixed> */
    private function listWith(string $query, int $eventCount): array
    {
        $this->publicAlice($this->events($eventCount));

        $response = $this->controller->listPublicEvents(
            'alice',
            Request::create('/api/v2/public/calendars/alice/events?start=20260401&end=20260430&' . $query),
        );

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true);

        return $body;
    }

    public function testTheDefaultPageHoldsTwentyEvents(): void
    {
        $body = $this->listWith('', 25);

        $this->assertCount(20, $body['data']);
        $this->assertSame(1, $body['meta']['page']);
        $this->assertSame(20, $body['meta']['limit']);
        $this->assertSame(25, $body['meta']['total']);
    }

    public function testThePageIsAtLeastOne(): void
    {
        // page=0 would otherwise make the offset negative, which array_slice
        // reads as "from the end".
        $body = $this->listWith('page=0&limit=2', 5);

        $this->assertSame(1, $body['meta']['page']);
        $this->assertSame([1, 2], array_column($body['data'], 'id'));
    }

    public function testTheLimitIsAtLeastOne(): void
    {
        $body = $this->listWith('limit=0', 5);

        $this->assertSame(1, $body['meta']['limit']);
        $this->assertCount(1, $body['data']);
    }

    public function testTheLimitIsCappedAtOneHundred(): void
    {
        $body = $this->listWith('limit=1000', 150);

        $this->assertSame(100, $body['meta']['limit']);
        $this->assertCount(100, $body['data']);
    }

    public function testAPageSelectsItsOwnSliceRatherThanAnAdjacentOne(): void
    {
        $body = $this->listWith('page=3&limit=2', 7);

        $this->assertSame([5, 6], array_column($body['data'], 'id'));
    }

    public function testAPagePastTheEndIsEmptyButTheTotalIsStillTheTruth(): void
    {
        $body = $this->listWith('page=9&limit=10', 5);

        $this->assertSame([], $body['data']);
        $this->assertSame(5, $body['meta']['total']);
    }

    // --- rate limiting ---

    public function testTheRateLimitIsKeyedOnTheClientAddress(): void
    {
        $this->rateLimiter->expects($this->once())->method('isAllowed')
            ->with('public_api:203.0.113.9', 'public_api', 30, 60)
            ->willReturn(true);
        $this->rateLimiter->expects($this->once())->method('recordAttempt')
            ->with('public_api:203.0.113.9', 'public_api', 60);
        $this->userRepo->method('findAll')->willReturn([]);

        $request = Request::create('/api/v2/public/calendars', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.9']);

        $this->assertSame(200, $this->controller->listPublicCalendars($request)->getStatusCode());
    }

    public function testARequestWithNoClientAddressStillGetsAKey(): void
    {
        $this->rateLimiter->expects($this->once())->method('isAllowed')
            ->with('public_api:unknown', 'public_api', 30, 60)
            ->willReturn(true);
        $this->userRepo->method('findAll')->willReturn([]);

        // No REMOTE_ADDR at all, so getClientIp() is null.
        $this->controller->listPublicCalendars(new Request());
    }

    public function testARejectedRequestIsNotRecordedAsAnAttempt(): void
    {
        $this->rateLimiter->method('isAllowed')->willReturn(false);
        $this->rateLimiter->expects($this->never())->method('recordAttempt');

        $response = $this->controller->listPublicCalendars(Request::create('/api/v2/public/calendars'));

        $this->assertSame(429, $response->getStatusCode());
    }

    // --- the public flag ---

    public function testAnotherPreferenceSetToYesDoesNotMakeACalendarPublic(): void
    {
        // The key and the value both have to match; either alone must not do.
        $this->allowRateLimit();
        $this->userRepo->method('findByLogin')->willReturn($this->makeUser('alice'));
        $this->userRepo->method('getPreferences')->willReturn([
            new UserPreference('some_other_flag', 'Y'),
            new UserPreference('public_calendar_enabled', 'N'),
        ]);

        $response = $this->controller->listPublicEvents(
            'alice',
            Request::create('/api/v2/public/calendars/alice/events?start=20260401&end=20260430'),
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function toggleBodies(): iterable
    {
        yield 'true enables' => ['{"enabled":true}', 'Y', true];
        yield 'false disables' => ['{"enabled":false}', 'N', false];
        yield 'absent disables' => ['{}', 'N', false];
        yield 'the string "true" is not true' => ['{"enabled":"true"}', 'N', false];
        yield 'one is not true' => ['{"enabled":1}', 'N', false];
    }

    #[DataProvider('toggleBodies')]
    public function testOnlyABooleanTrueEnablesTheCalendar(string $json, string $stored, bool $reported): void
    {
        $this->userRepo->method('findByLogin')->willReturn($this->makeUser('bob'));
        $this->userRepo->expects($this->once())->method('savePreference')
            ->with('bob', $this->callback(
                static fn(UserPreference $p): bool => $p->key() === 'public_calendar_enabled' && $p->value() === $stored,
            ));

        $request = Request::create('/api/v2/admin/users/bob/public-calendar', 'PUT', [], [], [], [], $json);
        $response = $this->controller->togglePublicCalendar('bob', $request, $this->makeUser('admin', admin: true));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame('bob', $body['data']['login']);
        $this->assertSame($reported, $body['data']['public_calendar_enabled']);
    }
}
