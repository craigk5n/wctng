<?php

declare(strict_types=1);

namespace App\Controller\Api\Event;

use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use App\Service\EventUserPolicy;
use App\Service\MercurePublisher;
use App\Service\TenantAwarePdoProvider;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use WebCalendar\Core\Application\Service\ActivityLogService;
use WebCalendar\Core\Application\Service\EventService;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\ActivityLogType;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\ParticipantStatus;

/**
 * Restore (undo) a soft-deleted event or participant decline.
 */
final class RestoreEventController
{
    private const AWAITING_APPROVAL = 'needs_approval';

    public function __construct(
        private readonly EventService $eventService,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly MercurePublisher $mercure,
        private readonly ActivityLogService $activityLogService,
        private readonly TenantAwarePdoProvider $pdoProvider,
        private readonly EventUserPolicy $userPolicy,
        // Defaulted so the container autowires the real logger while code
        // that constructs this directly keeps working.
        private readonly LoggerInterface $logger = new NullLogger(),
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

        /** @var mixed $decoded */
        $decoded = json_decode($request->getContent(), true);
        /** @var array<string, mixed> $body */
        $body = \is_array($decoded) ? $decoded : [];
        /** @var mixed $prevStatus */
        $prevStatus = $body['previous_status'] ?? null;

        if ($isOrganizer || $isAdmin) {
            // Restore cancelled event
            if ($existing->status() !== 'cancelled') {
                return ApiResponse::error(400, 'Event is not cancelled');
            }

            // previous_status is a value the delete response handed the
            // client, which sends it back here -- so it is the client's to
            // choose, not evidence of what the entry was. Written into
            // cal_status unchecked it let an organiser bring an entry back as
            // anything, approved included, which is not theirs to decide.
            //
            // Restoring publishes the entry again, so it goes through the gate
            // that creating one does, read off the organiser's own settings.
            $restoreTo = $this->userPolicy->requiresApproval($existing->createdBy())
                ? self::AWAITING_APPROVAL
                : null;
            $pdo = $this->pdoProvider->get();
            $stmt = $pdo->prepare(
                'UPDATE webcal_entry SET cal_status = :status WHERE cal_id = :id'
            );
            $stmt->execute(['status' => $restoreTo, 'id' => $id]);

            try {
                $this->mercure->publishEventUpdated($id, ['action' => 'restored']);
            } catch (\Throwable $e) {
                $this->logger->warning('mercure->publishEventUpdated() failed', ['exception' => $e->getMessage()]);
            }

            try {
                $this->activityLogService->log(
                    $id,
                    $login,
                    null,
                    ActivityLogType::UPDATE,
                    'Restored event: ' . $existing->name(),
                );
            } catch (\Throwable $e) {
                $this->logger->warning('activityLogService->log() failed', ['exception' => $e->getMessage()]);
            }

            return ApiResponse::success(['restored' => true]);
        }

        // Participant: restore their acceptance
        $restoreTo = self::readParticipantStatus($prevStatus);
        $this->eventRepository
            ->updateParticipantStatus(new EventId($id), $login, $restoreTo);

        try {
            $this->mercure->publishParticipantChanged($id, ['action' => 'restored', 'login' => $login]);
        } catch (\Throwable $e) {
            $this->logger->warning('mercure->publishParticipantChanged() failed', ['exception' => $e->getMessage()]);
        }

        return ApiResponse::success(['restored' => true]);
    }

    /**
     * The seat a participant gets back.
     *
     * webcal_entry_user.cal_status is a CHAR(1) and ParticipantStatus names
     * the six it can hold; anything else used to be written to it anyway.
     * Coming back as declined is not a restore either -- that is the state
     * being undone -- so both fall back to accepted.
     */
    private static function readParticipantStatus(mixed $value): string
    {
        if (!\is_string($value)) {
            return ParticipantStatus::ACCEPTED->value;
        }

        $status = ParticipantStatus::tryFrom($value);

        if ($status === null || $status === ParticipantStatus::REJECTED) {
            return ParticipantStatus::ACCEPTED->value;
        }

        return $status->value;
    }
}
