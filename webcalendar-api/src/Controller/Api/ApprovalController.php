<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\DTO\EventResponseDTO;
use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\Repository\UserRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\EventId;

final class ApprovalController
{
    public function __construct(
        private readonly EventRepositoryInterface $eventRepo,
    ) {}

    public static function createForTest(
        EventRepositoryInterface $eventRepo,
        ?UserRepositoryInterface $userRepo = null,
    ): self {
        $instance = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $ref = new \ReflectionProperty(self::class, 'eventRepo');
        $ref->setValue($instance, $eventRepo);
        return $instance;
    }

    #[Route('/api/v2/admin/events/pending', name: 'api_admin_events_pending', methods: ['GET'])]
    public function listPending(
        Request $request,
        #[CurrentUser]
        WebCalendarUser|User|null $actorOrUser = null,
    ): JsonResponse {
        $actor = $this->resolveUser($actorOrUser);
        if ($actor === null || !$actor->isAdmin()) {
            return ApiResponse::error(403, 'Admin access required');
        }

        $events = array_values($this->eventRepo->findByStatus('needs_approval'));
        $items = EventResponseDTO::fromCollection($events);

        return ApiResponse::success($items);
    }

    #[Route('/api/v2/admin/events/{id}/approve', name: 'api_admin_event_approve', methods: ['PUT'])]
    public function approve(
        int $id,
        Request $request,
        #[CurrentUser]
        WebCalendarUser|User|null $actorOrUser = null,
    ): JsonResponse {
        $actor = $this->resolveUser($actorOrUser);
        if ($actor === null || !$actor->isAdmin()) {
            return ApiResponse::error(403, 'Admin access required');
        }

        $event = $this->eventRepo->findById(new EventId($id));
        if ($event === null) {
            return ApiResponse::error(404, 'Event not found');
        }

        $updated = new Event(
            id: $event->id(),
            uid: $event->uid(),
            name: $event->name(),
            description: $event->description(),
            location: $event->location(),
            start: $event->start(),
            duration: $event->duration(),
            createdBy: $event->createdBy(),
            type: $event->type(),
            access: $event->access(),
            recurrence: $event->recurrence(),
            sequence: $event->sequence(),
            status: 'confirmed',
            allDay: $event->isAllDay(),
        );

        $this->eventRepo->save($updated);

        return ApiResponse::success(EventResponseDTO::fromEntity($updated));
    }

    #[Route('/api/v2/admin/events/{id}/reject', name: 'api_admin_event_reject', methods: ['PUT'])]
    public function reject(
        int $id,
        Request $request,
        #[CurrentUser]
        WebCalendarUser|User|null $actorOrUser = null,
    ): JsonResponse {
        $actor = $this->resolveUser($actorOrUser);
        if ($actor === null || !$actor->isAdmin()) {
            return ApiResponse::error(403, 'Admin access required');
        }

        $event = $this->eventRepo->findById(new EventId($id));
        if ($event === null) {
            return ApiResponse::error(404, 'Event not found');
        }

        $updated = new Event(
            id: $event->id(),
            uid: $event->uid(),
            name: $event->name(),
            description: $event->description(),
            location: $event->location(),
            start: $event->start(),
            duration: $event->duration(),
            createdBy: $event->createdBy(),
            type: $event->type(),
            access: $event->access(),
            recurrence: $event->recurrence(),
            sequence: $event->sequence(),
            status: 'rejected',
            allDay: $event->isAllDay(),
        );

        $this->eventRepo->save($updated);

        return ApiResponse::success(EventResponseDTO::fromEntity($updated));
    }

    private function resolveUser(WebCalendarUser|User|null $actorOrUser): ?User
    {
        if ($actorOrUser instanceof WebCalendarUser) {
            return $actorOrUser->getCoreUser();
        }
        return $actorOrUser;
    }
}
