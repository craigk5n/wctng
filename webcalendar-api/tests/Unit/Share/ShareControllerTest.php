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
use WebCalendar\Core\Domain\ValueObject\DateRange;
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
}
