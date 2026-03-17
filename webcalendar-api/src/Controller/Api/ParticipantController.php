<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use App\Service\CoreServiceFactory;
use App\Service\EventNotificationService;
use App\Service\MercurePublisher;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\ParticipantStatus;

final class ParticipantController
{
    public function __construct(
        private readonly CoreServiceFactory $coreServiceFactory,
        private readonly MercurePublisher $mercure,
        private readonly EventNotificationService $notifications,
    ) {
    }

    #[Route('/api/v2/events/{eventId}/participants', name: 'api_participants_list', methods: ['GET'])]
    public function list(int $eventId, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $event = $this->coreServiceFactory->getEventService()->getEventById(new EventId($eventId));
        if ($event === null) {
            return ApiResponse::error(404, 'Event not found');
        }

        /** @var array<string, string> $participants */
        $participants = $this->coreServiceFactory->getEventRepository()->getParticipantsWithStatus(new EventId($eventId));

        $items = [];
        foreach ($participants as $login => $status) {
            $items[] = ['login' => $login, 'status' => $status];
        }

        return ApiResponse::success($items);
    }

    #[Route('/api/v2/events/{eventId}/participants', name: 'api_participants_add', methods: ['POST'])]
    public function add(int $eventId, Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $event = $this->coreServiceFactory->getEventService()->getEventById(new EventId($eventId));
        if ($event === null) {
            return ApiResponse::error(404, 'Event not found');
        }

        $decoded = json_decode($request->getContent(), true);
        if (!\is_array($decoded)) {
            return ApiResponse::error(400, 'Invalid JSON body');
        }

        /** @var array<string, mixed> $data */
        $data = $decoded;

        if (!isset($data['participants']) || !\is_array($data['participants'])) {
            return ApiResponse::error(400, 'Missing required field: participants');
        }

        $coreUser = $user->getCoreUser();
        $eventService = $this->coreServiceFactory->getEventService();

        /** @var list<string> $participantList */
        $participantList = $data['participants'];
        foreach ($participantList as $login) {
            if ($login !== '') {
                $eventService->addParticipant(new EventId($eventId), $login, $coreUser);
            }
        }

        try {
            $this->mercure->publishParticipantChanged($eventId, ['action' => 'added', 'participants' => $participantList]);
        } catch (\Throwable) {
        }

        // Send invitation emails to new participants
        try {
            $eventEntity = $this->coreServiceFactory->getEventService()->getEventById(new EventId($eventId));
            if ($eventEntity !== null) {
                $eventData = \App\DTO\EventResponseDTO::fromEntity($eventEntity);
                $this->notifications->notifyParticipantsAdded($eventData, $participantList);
            }
        } catch (\Throwable) {
        }

        return ApiResponse::success(['message' => 'Participants added']);
    }

    #[Route('/api/v2/events/{eventId}/participants/{login}', name: 'api_participants_remove', methods: ['DELETE'])]
    public function remove(int $eventId, string $login, #[CurrentUser] ?WebCalendarUser $user): Response
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $event = $this->coreServiceFactory->getEventService()->getEventById(new EventId($eventId));
        if ($event === null) {
            return ApiResponse::error(404, 'Event not found');
        }

        $coreUser = $user->getCoreUser();
        $this->coreServiceFactory->getEventService()->removeParticipant(new EventId($eventId), $login, $coreUser);

        try {
            $this->mercure->publishParticipantChanged($eventId, ['action' => 'removed', 'login' => $login]);
        } catch (\Throwable) {
        }

        return ApiResponse::noContent();
    }

    #[Route('/api/v2/events/{eventId}/participants/{login}', name: 'api_participants_update_status', methods: ['PUT'])]
    public function updateStatus(int $eventId, string $login, Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $event = $this->coreServiceFactory->getEventService()->getEventById(new EventId($eventId));
        if ($event === null) {
            return ApiResponse::error(404, 'Event not found');
        }

        $decoded = json_decode($request->getContent(), true);
        if (!\is_array($decoded)) {
            return ApiResponse::error(400, 'Invalid JSON body');
        }

        /** @var array<string, mixed> $data */
        $data = $decoded;

        $statusStr = isset($data['status']) && \is_string($data['status']) ? $data['status'] : null;
        if ($statusStr === null) {
            return ApiResponse::error(400, 'Missing required field: status');
        }

        $status = ParticipantStatus::tryFrom($statusStr);
        if ($status === null) {
            return ApiResponse::error(400, 'Invalid status. Valid values: A, C, D, P, R, W');
        }

        $coreUser = $user->getCoreUser();
        $this->coreServiceFactory->getEventService()->setParticipantStatus(
            new EventId($eventId),
            $login,
            $status,
            $coreUser,
        );

        return ApiResponse::success(['login' => $login, 'status' => $status->value]);
    }

    #[Route('/api/v2/events/{eventId}/approve', name: 'api_events_approve', methods: ['POST'])]
    public function approve(int $eventId, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $event = $this->coreServiceFactory->getEventService()->getEventById(new EventId($eventId));
        if ($event === null) {
            return ApiResponse::error(404, 'Event not found');
        }

        $coreUser = $user->getCoreUser();
        $this->coreServiceFactory->getEventService()->approveEvent(
            new EventId($eventId),
            $coreUser->login(),
            $coreUser,
        );

        try {
            $this->mercure->publishParticipantChanged($eventId, ['action' => 'approved', 'login' => $coreUser->login()]);
        } catch (\Throwable) {
        }

        return ApiResponse::success(['login' => $coreUser->login(), 'status' => 'A']);
    }

    #[Route('/api/v2/events/{eventId}/reject', name: 'api_events_reject', methods: ['POST'])]
    public function reject(int $eventId, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $event = $this->coreServiceFactory->getEventService()->getEventById(new EventId($eventId));
        if ($event === null) {
            return ApiResponse::error(404, 'Event not found');
        }

        $coreUser = $user->getCoreUser();
        $this->coreServiceFactory->getEventService()->rejectEvent(
            new EventId($eventId),
            $coreUser->login(),
            $coreUser,
        );

        try {
            $this->mercure->publishParticipantChanged($eventId, ['action' => 'rejected', 'login' => $coreUser->login()]);
        } catch (\Throwable) {
        }

        return ApiResponse::success(['login' => $coreUser->login(), 'status' => 'R']);
    }
}
