<?php

declare(strict_types=1);

namespace App\Controller\Api\Event;

use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use App\Service\MercurePublisher;
use App\Service\TenantAwarePdoProvider;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use WebCalendar\Core\Application\Service\ActivityLogService;
use WebCalendar\Core\Application\Service\EventService;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\ActivityLogType;
use WebCalendar\Core\Domain\ValueObject\EventId;

/**
 * Restore (undo) a soft-deleted event or participant decline.
 */
final class RestoreEventController
{
    public function __construct(
        private readonly EventService $eventService,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly MercurePublisher $mercure,
        private readonly ActivityLogService $activityLogService,
        private readonly TenantAwarePdoProvider $pdoProvider,
    ) {}

    #[Route('/api/v2/events/{id}/restore', name: 'api_events_restore', methods: ['POST'])]
    public function __invoke(int $id, Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $existing = $this->eventService->getEventById(new EventId($id));

        if ($existing === null) {
            return ApiResponse::error(404, 'Event not found');
        }

        $coreUser = $user->getCoreUser();
        $login = $coreUser->login();
        $isOrganizer = $existing->createdBy() === $login;
        $isAdmin = $coreUser->isAdmin();

        /** @var array{previous_status?: string} $body */
        $body = json_decode($request->getContent(), true) ?? [];
        $prevStatus = $body['previous_status'] ?? null;

        if ($isOrganizer || $isAdmin) {
            // Restore cancelled event
            if ($existing->status() !== 'cancelled') {
                return ApiResponse::error(400, 'Event is not cancelled');
            }

            $restoreTo = ($prevStatus !== null && $prevStatus !== 'cancelled') ? $prevStatus : null;
            $pdo = $this->pdoProvider->get();
            $stmt = $pdo->prepare(
                'UPDATE webcal_entry SET cal_status = :status WHERE cal_id = :id'
            );
            $stmt->execute(['status' => $restoreTo, 'id' => $id]);

            try {
                $this->mercure->publishEventUpdated($id, ['action' => 'restored']);
            } catch (\Throwable) {
            }

            try {
                $this->activityLogService->log(
                    $id,
                    $login,
                    null,
                    ActivityLogType::UPDATE,
                    'Restored event: ' . $existing->name(),
                );
            } catch (\Throwable) {
            }

            return ApiResponse::success(['restored' => true]);
        }

        // Participant: restore their acceptance
        $restoreTo = ($prevStatus !== null && $prevStatus !== 'R') ? $prevStatus : 'A';
        $this->eventRepository
            ->updateParticipantStatus(new EventId($id), $login, $restoreTo);

        try {
            $this->mercure->publishParticipantChanged($id, ['action' => 'restored', 'login' => $login]);
        } catch (\Throwable) {
        }

        return ApiResponse::success(['restored' => true]);
    }
}
