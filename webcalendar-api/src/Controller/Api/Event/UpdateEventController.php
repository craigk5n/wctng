<?php

declare(strict_types=1);

namespace App\Controller\Api\Event;

use App\DTO\EventRequestDTO;
use App\DTO\EventResponseDTO;
use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use App\Service\ConflictDetectionService;
use App\Service\DescriptionSanitizer;
use App\Service\EventInputParser;
use App\Service\EventNotificationService;
use App\Service\EventRecurrenceService;
use App\Service\EventUserPolicy;
use App\Service\ExtParticipantRepository;
use App\Service\ExtParticipantValidator;
use App\Service\GeocodingService;
use App\Service\GeoRepository;
use App\Service\MercurePublisher;
use App\Webhook\WebhookDispatcher;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use WebCalendar\Core\Application\Service\ActivityLogService;
use WebCalendar\Core\Application\Service\CategoryService;
use WebCalendar\Core\Application\Service\ConfigService;
use WebCalendar\Core\Application\Service\EventService;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\ActivityLogType;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventId;

final class UpdateEventController
{
    public function __construct(
        private readonly EventService $eventService,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly CategoryService $categoryService,
        private readonly DescriptionSanitizer $descriptionSanitizer,
        private readonly ConflictDetectionService $conflictService,
        private readonly EventUserPolicy $userPolicy,
        private readonly ExtParticipantRepository $extParticipants,
        private readonly ExtParticipantValidator $extParticipantValidator,
        private readonly ConfigService $configService,
        private readonly GeoRepository $geoRepository,
        private readonly ?GeocodingService $geocodingService,
        private readonly EventNotificationService $notifications,
        private readonly WebhookDispatcher $webhookDispatcher,
        private readonly MercurePublisher $mercure,
        private readonly ActivityLogService $activityLogService,
        private readonly EventRecurrenceService $recurrenceService,
    ) {}

    /**
     * Update an event.
     *
     * Query params for recurring events:
     * - scope=all (default) — edit entire series
     * - scope=future&from_date=YYYYMMDD — split series at date, apply edits to new series
     */
    #[Route('/api/v2/events/{id}', name: 'api_events_update', methods: ['PUT'])]
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

        // Check ownership (only owner or admin can update)
        if ($existing->createdBy() !== $coreUser->login() && !$coreUser->isAdmin()) {
            return ApiResponse::error(403, 'You do not have permission to update this event');
        }

        // Handle series split: scope=future splits the series and applies edits to the new one
        $editScope = $request->query->getString('scope', 'all');
        if ($editScope === 'future' && $existing->recurrence()->isRepeating()) {
            $fromDateStr = $request->query->getString('from_date', '');
            $fromDate = EventInputParser::parseDateParam($fromDateStr);
            if ($fromDate === null) {
                return ApiResponse::error(400, 'Missing or invalid from_date parameter (YYYYMMDD)');
            }

            $splitResult = $this->recurrenceService->splitSeriesAtDate($id, $existing, $fromDate, $coreUser->login());
            $newId = $splitResult['new_id'];

            // Now apply the edits to the NEW event instead of the original
            $id = $newId;
            $newEvent = $this->eventService->getEventById(new EventId($newId));
            if ($newEvent === null) {
                return ApiResponse::error(500, 'Split succeeded but new event not found');
            }
            $existing = $newEvent;
        }

        $decoded = json_decode($request->getContent(), true);

        if (!\is_array($decoded)) {
            return ApiResponse::error(400, 'Invalid JSON body');
        }

        /** @var array<string, mixed> $data */
        $data = $decoded;

        if (isset($data['description']) && \is_string($data['description'])) {
            $data['description'] = $this->descriptionSanitizer->sanitize($data['description']);
        }

        try {
            $updated = EventRequestDTO::applyUpdate($data, $existing);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error(400, $e->getMessage());
        }

        // Parse ext_participants if present; undefined = leave alone, [] = clear
        $extParticipantsUpdate = null;
        if (\array_key_exists('ext_participants', $data)) {
            try {
                $extParticipantsUpdate = $this->extParticipantValidator->parse($data['ext_participants']);
            } catch (\InvalidArgumentException $e) {
                return ApiResponse::error(400, $e->getMessage());
            }
            $isExtParticipantsDisabled = false;
            try {
                $value = $this->configService->getSetting('DISABLE_EXT_PARTICIPANTS_FIELD', 'N');
                $isExtParticipantsDisabled = $value === 'Y';
            } catch (\Throwable) {
            }
            if ($extParticipantsUpdate !== [] && $isExtParticipantsDisabled) {
                return ApiResponse::error(403, 'External participants are disabled by the administrator');
            }
        }

        // Check for conflicts
        $conflictMode = $this->userPolicy->getConflictMode($user->getUserIdentifier());
        $conflictList = [];
        if ($conflictMode !== 'off') {
            $dateRange = new DateRange($updated->start()->modify('-1 day'), $updated->end()->modify('+1 day'));
            $allEvents = $this->eventService
                ->getEventsInDateRange($dateRange, $coreUser)->all();
            $conflicts = $this->conflictService->findConflicts($updated, $allEvents, $id);
            $conflictList = $this->conflictService->formatConflicts($conflicts);

            if (\count($conflictList) > 0 && $conflictMode === 'block') {
                return ApiResponse::error(409, 'Event conflicts with existing events', array_map(
                    static fn(array $c): string => sprintf('%s (%s)', $c['title'], $c['start']),
                    $conflictList,
                ));
            }
        }

        $this->eventService->updateEvent($updated, $coreUser);

        // Update categories if provided
        $categoryIds = EventInputParser::parseCategoryIds($data);
        if (isset($data['categories'])) {
            $this->categoryService->assignToEvent(
                new EventId($id),
                $user->getUserIdentifier(),
                $categoryIds,
                $coreUser,
            );
        }

        // Apply ext_participants update now that the event row is saved.
        if ($extParticipantsUpdate !== null) {
            $this->extParticipants->saveForEvent($id, $extParticipantsUpdate);
        }

        // Re-fetch to return the saved state
        $saved = $this->eventService->getEventById(new EventId($id));

        if ($saved === null) {
            return ApiResponse::error(500, 'Event updated but could not be retrieved');
        }

        // Re-geocode if location changed
        $geo = null;
        try {
            if ($this->geocodingService !== null && $saved->location() !== $existing->location()) {
                $this->geocodingService->geocodeEvent($id, $saved->location());
            }
            $geo = $this->geoRepository->getCoordinates($id);
        } catch (\Throwable) {
        }

        $responseData = EventResponseDTO::fromEntity($saved, $categoryIds, $geo);
        $responseData['ext_participants'] = $this->extParticipants->findForEvent($id);

        try {
            $this->mercure->publishEventUpdated($id, $responseData);
        } catch (\Throwable) {
        }

        try {
            $this->webhookDispatcher->dispatch('event.updated', $responseData);
        } catch (\Throwable) {
        }

        // Notify participants of update
        try {
            /** @var array<string, string> $participants */
            $participants = $this->eventRepository->getParticipantsWithStatus(new EventId($id));
            $pList = [];
            foreach ($participants as $login => $status) {
                $pList[] = ['login' => $login, 'status' => $status];
            }
            $this->notifications->notifyEventUpdated($responseData, $pList);
        } catch (\Throwable) {
        }

        // Notify external participants of update
        if ($extParticipantsUpdate !== null) {
            try {
                $this->notifications->notifyExtParticipantsUpdated($responseData, $extParticipantsUpdate);
            } catch (\Throwable) {
            }
        }

        // Log activity
        try {
            $this->activityLogService->log(
                $id,
                $user->getUserIdentifier(),
                null,
                ActivityLogType::UPDATE,
                'Updated event: ' . $saved->name(),
            );
        } catch (\Throwable) {
        }

        $meta = \count($conflictList) > 0 ? ['conflicts' => $conflictList] : null;
        return ApiResponse::success($responseData, $meta);
    }
}
