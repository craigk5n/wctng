<?php

declare(strict_types=1);

namespace App\Tests\Unit\Share;

use App\Controller\Api\ShareController;
use App\Share\ShareTokenRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\Recurrence;

final class ShareControllerTest extends TestCase
{
    private \PDO $pdo;
    private ShareTokenRepository $tokenRepo;
    private EventRepositoryInterface $eventRepo;
    private ShareController $controller;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->tokenRepo = new ShareTokenRepository($this->pdo);
        $this->eventRepo = $this->createMock(EventRepositoryInterface::class);
        $this->controller = ShareController::createForTest($this->tokenRepo, $this->eventRepo);
    }

    private function makeUser(string $login, bool $admin = false): User
    {
        return new User($login, ucfirst($login), 'Smith', $login . '@example.com', $admin, true);
    }

    private function makeEvent(int $id, string $createdBy): Event
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
            access: AccessLevel::PUBLIC,
            recurrence: new Recurrence(),
        );
    }

    public function testCreateShareToken(): void
    {
        $user = $this->makeUser('alice');

        $request = Request::create(
            '/api/v2/calendars/share',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([], \JSON_THROW_ON_ERROR),
        );

        $response = $this->controller->createShareToken($request, $user);
        $this->assertSame(201, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true);
        $this->assertNotNull($body['data']['token']);
        $this->assertSame('alice', $body['data']['owner_login']);
    }

    public function testCreateShareTokenWithExpiry(): void
    {
        $user = $this->makeUser('alice');

        $request = Request::create(
            '/api/v2/calendars/share',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['expires_at' => '2026-12-31 23:59:59'], \JSON_THROW_ON_ERROR),
        );

        $response = $this->controller->createShareToken($request, $user);
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame('2026-12-31 23:59:59', $body['data']['expires_at']);
    }

    public function testListShareTokens(): void
    {
        $this->tokenRepo->create('t1', 'alice', null);
        $this->tokenRepo->create('t2', 'alice', null);

        $user = $this->makeUser('alice');
        $request = Request::create('/api/v2/calendars/share');

        $response = $this->controller->listShareTokens($request, $user);
        $this->assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true);
        $this->assertCount(2, $body['data']);
    }

    public function testDeleteShareToken(): void
    {
        $this->tokenRepo->create('del-token', 'alice', null);

        $user = $this->makeUser('alice');
        $request = Request::create('/api/v2/calendars/share/del-token', 'DELETE');

        $response = $this->controller->deleteShareToken('del-token', $request, $user);
        $this->assertSame(204, $response->getStatusCode());
        $this->assertNull($this->tokenRepo->findByToken('del-token'));
    }

    public function testDeleteShareTokenNotOwned(): void
    {
        $this->tokenRepo->create('not-mine', 'bob', null);

        $user = $this->makeUser('alice');
        $request = Request::create('/api/v2/calendars/share/not-mine', 'DELETE');

        $response = $this->controller->deleteShareToken('not-mine', $request, $user);
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testSharedEvents(): void
    {
        $this->tokenRepo->create('valid-token', 'alice', null);

        $events = [$this->makeEvent(1, 'alice'), $this->makeEvent(2, 'alice')];
        $this->eventRepo->method('findByDateRange')->willReturn($events);

        $request = Request::create('/api/v2/public/shared/valid-token/events?start=20260401&end=20260430');
        $response = $this->controller->sharedEvents('valid-token', $request);

        $this->assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true);
        $this->assertCount(2, $body['data']);
    }

    public function testSharedEventsInvalidToken(): void
    {
        $request = Request::create('/api/v2/public/shared/bad-token/events?start=20260401&end=20260430');
        $response = $this->controller->sharedEvents('bad-token', $request);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testSharedEventsExpiredToken(): void
    {
        $this->tokenRepo->create('expired', 'alice', '2020-01-01 00:00:00');

        $request = Request::create('/api/v2/public/shared/expired/events?start=20260401&end=20260430');
        $response = $this->controller->sharedEvents('expired', $request);

        $this->assertSame(410, $response->getStatusCode());
    }

    public function testSharedEventsRequiresDateParams(): void
    {
        $this->tokenRepo->create('needs-dates', 'alice', null);

        $request = Request::create('/api/v2/public/shared/needs-dates/events');
        $response = $this->controller->sharedEvents('needs-dates', $request);

        $this->assertSame(400, $response->getStatusCode());
    }
    /** @return array<string, mixed> */
    private function sharedEventsBody(string $query, int $eventCount = 5): array
    {
        $this->tokenRepo->create('paging-token', 'alice', null);

        $events = [];
        for ($i = 1; $i <= $eventCount; $i++) {
            $events[] = $this->makeEvent($i, 'alice');
        }
        $this->eventRepo->method('findByDateRange')->willReturn($events);

        $response = $this->controller->sharedEvents(
            'paging-token',
            Request::create('/api/v2/public/shared/paging-token/events?start=20260401&end=20260430&' . $query),
        );

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true);

        return $body;
    }

    public function testPageAndLimitSelectTheRightSlice(): void
    {
        // offset is (page - 1) * limit; an off-by-one either way lands on a
        // different event, and nothing checked which events came back.
        $body = $this->sharedEventsBody('page=2&limit=2');

        self::assertCount(2, $body['data']);
        self::assertSame('Test Event 3', $body['data'][0]['title']);
        self::assertSame('Test Event 4', $body['data'][1]['title']);
        self::assertSame(5, $body['meta']['total'], 'total counts every match, not the page');
        self::assertSame(2, $body['meta']['page']);
        self::assertSame(2, $body['meta']['limit']);
    }

    public function testLimitIsCappedAtOneHundred(): void
    {
        self::assertSame(100, $this->sharedEventsBody('limit=500')['meta']['limit']);
    }

    public function testLimitBelowOneIsRaisedToOne(): void
    {
        $body = $this->sharedEventsBody('limit=0');

        self::assertSame(1, $body['meta']['limit']);
        self::assertCount(1, $body['data']);
    }

    public function testPageBelowOneIsRaisedToOne(): void
    {
        $body = $this->sharedEventsBody('page=0&limit=2');

        self::assertSame(1, $body['meta']['page']);
        // Page 1, not a negative offset into the list.
        self::assertSame('Test Event 1', $body['data'][0]['title']);
    }

    public function testPagePastTheEndReturnsNothingButStillReportsTheTotal(): void
    {
        $body = $this->sharedEventsBody('page=9&limit=2');

        self::assertSame([], $body['data']);
        self::assertSame(5, $body['meta']['total']);
    }

    public function testDateParamsAreNormalisedToMidnight(): void
    {
        $this->tokenRepo->create('range-token', 'alice', null);

        $captured = null;
        $this->eventRepo->method('findByDateRange')->willReturnCallback(
            function (mixed $range) use (&$captured): array {
                $captured = $range;

                return [];
            },
        );

        $this->controller->sharedEvents(
            'range-token',
            Request::create('/api/v2/public/shared/range-token/events?start=20260401&end=20260430'),
        );

        self::assertNotNull($captured);
        // createFromFormat('Ymd') leaves the current time of day on the date,
        // so without setTime() the window drifts with the clock.
        self::assertSame('2026-04-01 00:00:00', $captured->startDate()->format('Y-m-d H:i:s'));
        self::assertSame('2026-04-30 00:00:00', $captured->endDate()->format('Y-m-d H:i:s'));
    }

    public function testGeneratedTokenIsAVersion4Uuid(): void
    {
        $request = Request::create(
            '/api/v2/calendars/share',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode([]),
        );

        $response = $this->controller->createShareToken($request, $this->makeUser('alice'));
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true);

        // The version nibble and the variant bits are set with `| 0x4000` and
        // `| 0x8000`; drop either and this is no longer a v4 UUID.
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            (string) $body['data']['token'],
        );
    }

    public function testListedTokensAreSerialisedAsArrays(): void
    {
        $this->tokenRepo->create('t1', 'alice', null);

        $response = $this->controller->listShareTokens(
            Request::create('/api/v2/calendars/share'),
            $this->makeUser('alice'),
        );

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true);

        self::assertCount(1, $body['data']);
        self::assertIsArray($body['data'][0], 'array_map applies toArray() to every row');
        self::assertSame('t1', $body['data'][0]['token']);
    }
    public function testMissingEitherDateParamAloneIsRejected(): void
    {
        // The check is an ||: with an && only a request missing *both* would
        // be rejected, and one-sided windows would reach the repository.
        $this->tokenRepo->create('half-token', 'alice', null);

        foreach (['start=20260401', 'end=20260430'] as $onlyOne) {
            $response = $this->controller->sharedEvents(
                'half-token',
                Request::create('/api/v2/public/shared/half-token/events?' . $onlyOne),
            );

            self::assertSame(400, $response->getStatusCode(), "expected 400 for {$onlyOne}");
        }
    }

    public function testOneUnparseableDateIsRejected(): void
    {
        $this->tokenRepo->create('bad-date-token', 'alice', null);

        foreach (['start=nonsense&end=20260430', 'start=20260401&end=nonsense'] as $query) {
            $response = $this->controller->sharedEvents(
                'bad-date-token',
                Request::create('/api/v2/public/shared/bad-date-token/events?' . $query),
            );

            self::assertSame(400, $response->getStatusCode(), "expected 400 for {$query}");
        }
    }

    public function testPagingDefaultsToTwentyPerPage(): void
    {
        $body = $this->sharedEventsBody('', 3);

        self::assertSame(1, $body['meta']['page']);
        self::assertSame(20, $body['meta']['limit']);
    }

    public function testOnlyTheTokenOwnersEventsAreRequested(): void
    {
        // The owner login is what scopes a public share link to one calendar;
        // dropping it from the filter would widen the query to everyone.
        $this->tokenRepo->create('scoped-token', 'alice', null);

        $captured = null;
        $this->eventRepo->method('findByDateRange')->willReturnCallback(
            function (mixed $range, mixed $user, mixed $access, mixed $owners) use (&$captured): array {
                $captured = $owners;

                return [];
            },
        );

        $this->controller->sharedEvents(
            'scoped-token',
            Request::create('/api/v2/public/shared/scoped-token/events?start=20260401&end=20260430'),
        );

        self::assertSame(['alice'], $captured);
    }
}
