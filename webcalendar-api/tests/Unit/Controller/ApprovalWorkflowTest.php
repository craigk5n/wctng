<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Api\ApprovalController;
use App\Share\ShareTokenRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\Repository\UserRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\Recurrence;

final class ApprovalWorkflowTest extends TestCase
{
    private EventRepositoryInterface&MockObject $eventRepo;
    private UserRepositoryInterface&MockObject $userRepo;
    private ApprovalController $controller;

    protected function setUp(): void
    {
        $this->eventRepo = $this->createMock(EventRepositoryInterface::class);
        $this->userRepo = $this->createMock(UserRepositoryInterface::class);
        $this->controller = ApprovalController::createForTest($this->eventRepo, $this->userRepo);
    }

    private function makeUser(string $login, bool $admin = false): User
    {
        return new User($login, ucfirst($login), 'Smith', $login . '@example.com', $admin, true);
    }

    private function makeEvent(int $id, string $createdBy, ?string $status = null): Event
    {
        return new Event(
            id: new EventId($id),
            uid: "event-{$id}@test",
            name: "Event {$id}",
            description: '',
            location: '',
            start: new \DateTimeImmutable('2026-04-01 10:00:00'),
            duration: 60,
            createdBy: $createdBy,
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
            status: $status,
        );
    }

    public function testListPendingRequiresAdmin(): void
    {
        $user = $this->makeUser('alice', false);
        $response = $this->controller->listPending(Request::create('/'), $user);
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testListPendingReturnsNeedsApprovalEvents(): void
    {
        $admin = $this->makeUser('admin', true);

        $pending = [
            $this->makeEvent(1, 'alice', 'needs_approval'),
            $this->makeEvent(2, 'bob', 'needs_approval'),
        ];
        $this->eventRepo->method('findByStatus')->with('needs_approval')->willReturn($pending);

        $response = $this->controller->listPending(Request::create('/'), $admin);
        $this->assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true);
        $this->assertCount(2, $body['data']);
    }

    public function testApproveEvent(): void
    {
        $admin = $this->makeUser('admin', true);

        $event = $this->makeEvent(5, 'alice', 'needs_approval');
        $this->eventRepo->method('findById')->willReturn($event);

        $this->eventRepo->expects($this->once())
            ->method('save')
            ->with($this->callback(function (Event $e): bool {
                return $e->status() === 'confirmed' && $e->id()->value() === 5;
            }));

        $request = Request::create('/api/v2/admin/events/5/approve', 'PUT');
        $response = $this->controller->approve(5, $request, $admin);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testRejectEvent(): void
    {
        $admin = $this->makeUser('admin', true);

        $event = $this->makeEvent(5, 'alice', 'needs_approval');
        $this->eventRepo->method('findById')->willReturn($event);

        $this->eventRepo->expects($this->once())
            ->method('save')
            ->with($this->callback(function (Event $e): bool {
                return $e->status() === 'rejected' && $e->id()->value() === 5;
            }));

        $request = Request::create(
            '/api/v2/admin/events/5/reject',
            'PUT',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['reason' => 'Too many meetings'], \JSON_THROW_ON_ERROR),
        );
        $response = $this->controller->reject(5, $request, $admin);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testApproveRequiresAdmin(): void
    {
        $user = $this->makeUser('alice', false);
        $event = $this->makeEvent(5, 'alice', 'needs_approval');
        $this->eventRepo->method('findById')->willReturn($event);

        $request = Request::create('/api/v2/admin/events/5/approve', 'PUT');
        $response = $this->controller->approve(5, $request, $user);
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testApproveNonexistentEvent(): void
    {
        $admin = $this->makeUser('admin', true);
        $this->eventRepo->method('findById')->willReturn(null);

        $request = Request::create('/api/v2/admin/events/99/approve', 'PUT');
        $response = $this->controller->approve(99, $request, $admin);
        $this->assertSame(404, $response->getStatusCode());
    }
}
