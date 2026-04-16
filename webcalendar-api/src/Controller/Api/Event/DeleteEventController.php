<?php

declare(strict_types=1);

namespace App\Controller\Api\Event;

use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use App\Service\EventInputParser;
use App\Service\EventNotificationService;
use App\Service\EventRecurrenceService;
use App\Service\ExtParticipantRepository;
use App\Service\MercurePublisher;
use App\Service\TenantAwarePdoProvider;
use App\Webhook\WebhookDispatcher;
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
 * Soft-delete an event.
 *
 * Query params:
 * - scope=all (default) — cancel entire event/series
 * - scope=occurrence&date=YYYYMMDD — add EXDATE for one occurrence (recurring only)
 * - scope=future&date=YYYYMMDD — truncate series with UNTIL before date (recurring only)
 *
 * For scope=all:
 * - Organizer (or admin): sets cal_status='cancelled', sends cancellation
 *   notifications, bumps sequence. Event stays in DB for audit.
 * - Participant (not organizer): sets their webcal_entry_user status to 'R'
 *   (rejected/declined). Event remains visible to everyone else.
 *
 * Permanent removal is only via admin Purge (POST /admin/events/purge).
 */
final class DeleteEventController
{
    public function __construct(
        private readonly EventService $eventService,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly EventRecurrenceService $recurrenceService,
        private readonly ExtParticipantRepository $extParticipants,
        private readonly EventNotificationService $notifications,
        private readonly WebhookDispatcher $webhookDispatcher,
        private readonly MercurePublisher $mercure,
        private readonly ActivityLogService $activityLogService,
        private readonly TenantAwarePdoProvider $pdoProvider,
    ) {}

    #[Route('/api/v2/events/{id}', name: 'api_events_delete', methods: ['DELETE'])]
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

        $scope = $request->query->getString('scope', 'all');
        $dateStr = $request->query->getString('date', '');

        // --- Occurrence-level or future-truncate scope ---
        if (($scope === 'occurrence' || $scope === 'future') && ($isOrganizer || $isAdmin)) {
            if (!$existing->recurrence()->isRepeating()) {
                return ApiResponse::error(400, 'Event is not recurring');
            }
            $date = EventInputParser::parseDateParam($dateStr);
            if ($date === null) {
                return ApiResponse::error(400, 'Missing or invalid date parameter (YYYYMMDD)');
            }

            if ($scope === 'occurrence') {
                $result = $this->recurrenceService->cancelOccurrence($id, $existing, $date, $login);
                return new JsonResponse($result);
            }

            $result = $this->recurrenceService->cancelFutureOccurrences($id, $existing, $date, $login);
            return new JsonResponse($result);
        }

        // Check the caller is either organizer, admin, or a participant
        if (!$isOrganizer && !$isAdmin) {
            // Check if caller is at least a participant
            /** @var array<string, string> $participants */
            $participants = $this->eventRepository
                ->getParticipantsWithStatus(new EventId($id));
            if (!isset($participants[$login])) {
                return ApiResponse::error(403, 'You do not have permission to modify this event');
            }

            // Participant decline: set their status to rejected
            $previousStatus = $participants[$login];
            $this->eventRepository
                ->updateParticipantStatus(new EventId($id), $login, 'R');

            try {
                $this->mercure->publishParticipantChanged($id, ['action' => 'declined', 'login' => $login]);
            } catch (\Throwable) {
            }

            try {
                $this->activityLogService->log(
                    $id,
                    $login,
                    null,
                    ActivityLogType::UPDATE,
                    'Declined event: ' . $existing->name(),
                );
            } catch (\Throwable) {
            }

            return new JsonResponse([
                'action' => 'declined',
                'previous_status' => $previousStatus,
            ]);
        }

        // Organizer/admin: cancel the event (soft-delete)
        $previousStatus = $existing->status();

        // Update event status to cancelled and bump sequence
        $pdo = $this->pdoProvider->get();
        $stmt = $pdo->prepare(
            "UPDATE webcal_entry SET cal_status = 'cancelled', cal_sequence = cal_sequence + 1 WHERE cal_id = :id"
        );
        $stmt->execute(['id' => $id]);

        // Notify internal participants
        try {
            /** @var array<string, string> $participants */
            $participants = $this->eventRepository
                ->getParticipantsWithStatus(new EventId($id));
            $pList = [];
            foreach ($participants as $pLogin => $status) {
                $pList[] = ['login' => $pLogin, 'status' => $status];
            }
            $this->notifications->notifyEventDeleted($existing->name(), $pList);
        } catch (\Throwable) {
        }

        // Notify external participants
        try {
            $extParticipants = $this->extParticipants->findForEvent($id);
            $this->notifications->notifyExtParticipantsDeleted(
                [
                    'id' => $id,
                    'title' => $existing->name(),
                    'start_date' => $existing->start()->format('Ymd'),
                    'uid' => $existing->uid(),
                ],
                $extParticipants,
            );
        } catch (\Throwable) {
        }

        try {
            $this->mercure->publishEventDeleted($id);
        } catch (\Throwable) {
        }

        try {
            $this->webhookDispatcher->dispatch('event.cancelled', ['id' => $id]);
        } catch (\Throwable) {
        }

        try {
            $this->activityLogService->log(
                $id,
                $login,
                null,
                ActivityLogType::UPDATE,
                'Cancelled event: ' . $existing->name(),
            );
        } catch (\Throwable) {
        }

        return new JsonResponse([
            'action' => 'cancelled',
            'previous_status' => $previousStatus,
        ]);
    }
}
