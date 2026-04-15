<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\DTO\EventRequestDTO;
use App\DTO\EventResponseDTO;
use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use App\Service\ConflictDetectionService;
use App\Service\DescriptionSanitizer;
use App\Service\EventNotificationService;
use App\Service\ExtParticipantRepository;
use App\Service\ExtParticipantValidator;
use App\Service\GeocodingService;
use App\Service\GeoRepository;
use App\Service\MercurePublisher;
use App\Service\TenantAwarePdoProvider;
use App\Webhook\WebhookDispatcher;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use WebCalendar\Core\Application\Service\ActivityLogService;
use WebCalendar\Core\Application\Service\CategoryService;
use WebCalendar\Core\Application\Service\ConfigService;
use WebCalendar\Core\Application\Service\EventService;
use WebCalendar\Core\Application\Service\LayerService;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\Repository\UserRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\ActivityLogType;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Infrastructure\Persistence\PdoCategoryRepository;

final class EventController
{
    private readonly GeoRepository $geoRepository;
    private readonly ExtParticipantRepository $extParticipants;
    private readonly ExtParticipantValidator $extParticipantValidator;

    public function __construct(
        private readonly EventService $eventService,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly CategoryService $categoryService,
        private readonly PdoCategoryRepository $categoryRepository,
        private readonly ConfigService $configService,
        private readonly LayerService $layerService,
        private readonly UserRepositoryInterface $userRepository,
        private readonly ActivityLogService $activityLogService,
        private readonly TenantAwarePdoProvider $pdoProvider,
        private readonly MercurePublisher $mercure,
        private readonly \PDO $pdo,
        private readonly EventNotificationService $notifications,
        private readonly WebhookDispatcher $webhookDispatcher,
        private readonly DescriptionSanitizer $descriptionSanitizer = new DescriptionSanitizer(),
        private readonly ConflictDetectionService $conflictService = new ConflictDetectionService(),
        private readonly ?GeocodingService $geocodingService = null,
        private readonly ClockInterface $clock = new NativeClock(),
    ) {
        $this->geoRepository = new GeoRepository($pdo);
        $this->extParticipants = new ExtParticipantRepository($pdo);
        $this->extParticipantValidator = new ExtParticipantValidator();
    }

    #[Route('/api/v2/events', name: 'api_events_list', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $startStr = $request->query->getString('start', '');
        $endStr = $request->query->getString('end', '');

        if ($startStr === '' || $endStr === '') {
            return ApiResponse::error(400, 'Missing required query params: start, end (YYYYMMDD)');
        }

        $start = $this->parseDateParam($startStr);
        $end = $this->parseDateParam($endStr);

        if ($start === null || $end === null) {
            return ApiResponse::error(400, 'Invalid date format. Expected YYYYMMDD.');
        }

        $page = max(1, $request->query->getInt('page', 1));
        $maxPerPage = (int) ($this->configService->getSetting('MAX_EVENTS_PER_PAGE') ?? '1000');
        $limit = min($maxPerPage, max(1, $request->query->getInt('limit', $maxPerPage)));

        $dateRange = new DateRange($start, $end);
        $coreUser = $user->getCoreUser();
        $collection = $this->eventService->getEventsInDateRange($dateRange, $coreUser);

        $allEvents = $collection->all();

        // Include events from active layers when layers=1
        if ($request->query->getString('layers', '') === '1') {
            $layers = $this->layerService->getLayersForUser($user->getUserIdentifier());
            $layerUsers = array_map(static fn ($l) => $l->layerUser(), $layers);
            if (\count($layerUsers) > 0) {
                // Load access permissions for layered users
                $accessMap = $this->getAccessPermissions($user->getUserIdentifier(), array_values($layerUsers));

                $layerCollection = $this->eventService->getEventsInDateRange($dateRange, null, null, $layerUsers);
                // Merge, avoiding duplicates by event ID, filtering by access
                $existingIds = array_map(static fn ($e) => $e->id(), $allEvents);
                foreach ($layerCollection->all() as $layerEvent) {
                    if (\in_array($layerEvent->id(), $existingIds, true)) {
                        continue;
                    }
                    $eventOwner = $layerEvent->createdBy();
                    $access = $accessMap[$eventOwner] ?? null;

                    // Skip private events from users without view permission
                    if ($layerEvent->access()->value === 'R' && ($access === null || !$access['can_view'])) {
                        continue;
                    }

                    $allEvents[] = $layerEvent;
                }
            }
        }
        $total = \count($allEvents);
        $offset = ($page - 1) * $limit;
        $pageItems = array_values(\array_slice($allEvents, $offset, $limit));

        // Load category IDs for events in this page
        $eventIds = array_map(static fn ($e) => $e->id(), $pageItems);
        $categoryMap = [];
        if (\count($eventIds) > 0) {
            $categoryRepo = $this->categoryRepository;
            /** @var array<int, array{id: int, color: string|null}> $batchResult */
            $batchResult = $categoryRepo->getForEventsBatch($eventIds, $user->getUserIdentifier());
            foreach ($batchResult as $eventId => $catInfo) {
                $categoryMap[$eventId] = [$catInfo['id']];
            }
        }

        // Batch load geo coordinates
        $geoMap = $this->geoRepository->getCoordinatesBatch(
            array_map(static fn ($e) => $e->id()->value(), $pageItems),
        );

        $items = array_map(
            static fn (\WebCalendar\Core\Domain\Entity\Event $event): array => EventResponseDTO::fromEntity(
                $event,
                $categoryMap[$event->id()->value()] ?? [],
                $geoMap[$event->id()->value()] ?? null,
            ),
            $pageItems,
        );

        // Mask confidential events from other users with see_time_only access
        if (isset($accessMap)) {
            $currentLogin = $user->getUserIdentifier();
            $items = array_map(static function (array $item) use ($accessMap, $currentLogin): array {
                /** @var string $createdBy */
                $createdBy = $item['created_by'] ?? '';
                if ($createdBy !== $currentLogin && $createdBy !== '') {
                    $access = $accessMap[$createdBy] ?? null;
                    $eventAccess = $item['access'] ?? 'P';
                    if ($eventAccess === 'C' && ($access === null || $access['see_time_only'])) {
                        $item['title'] = 'Busy';
                        $item['description'] = '';
                        $item['location'] = '';
                    }
                }
                return $item;
            }, $items);
        }

        return ApiResponse::paginated($items, $total, $page, $limit);
    }

    #[Route('/api/v2/events/conflicts', name: 'api_events_conflicts', methods: ['GET'])]
    public function conflicts(Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $startStr = $request->query->getString('start', '');
        $endStr = $request->query->getString('end', '');

        if ($startStr === '' || $endStr === '') {
            return ApiResponse::error(400, 'Missing required query params: start, end (YYYYMMDD)');
        }

        $start = $this->parseDateParam($startStr);
        $end = $this->parseDateParam($endStr);

        if ($start === null || $end === null) {
            return ApiResponse::error(400, 'Invalid date format. Expected YYYYMMDD.');
        }

        $excludeId = $request->query->getInt('exclude_id', 0);
        $durationMinutes = $request->query->getInt('duration', 60);
        $allDay = $request->query->getString('all_day', '') === '1';

        // Create a temporary event to check conflicts against
        $checkEvent = new \WebCalendar\Core\Domain\Entity\Event(
            id: new EventId(0),
            uid: 'conflict-check',
            name: 'conflict-check',
            description: '',
            location: '',
            start: $start,
            duration: $durationMinutes,
            createdBy: $user->getUserIdentifier(),
            type: \WebCalendar\Core\Domain\ValueObject\EventType::EVENT,
            access: \WebCalendar\Core\Domain\ValueObject\AccessLevel::PUBLIC,
            allDay: $allDay,
        );

        $dateRange = new DateRange($start->modify('-1 day'), $end->modify('+1 day'));
        $coreUser = $user->getCoreUser();
        $existing = $this->eventService
            ->getEventsInDateRange($dateRange, $coreUser)->all();

        $conflicts = $this->conflictService->findConflicts(
            $checkEvent,
            $existing,
            $excludeId > 0 ? $excludeId : null,
        );

        return ApiResponse::success($this->conflictService->formatConflicts($conflicts));
    }

    #[Route('/api/v2/events', name: 'api_events_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
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
            $event = EventRequestDTO::toEntity($data, $user->getUserIdentifier());
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error(400, $e->getMessage());
        }

        try {
            $extParticipants = $this->extParticipantValidator->parse($data['ext_participants'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error(400, $e->getMessage());
        }
        if ($extParticipants !== [] && $this->isExtParticipantsDisabled()) {
            return ApiResponse::error(403, 'External participants are disabled by the administrator');
        }

        $coreUser = $user->getCoreUser();

        // Check for conflicts
        $conflictMode = $this->getConflictMode($user->getUserIdentifier());
        $conflictList = [];
        if ($conflictMode !== 'off') {
            $dateRange = new DateRange($event->start()->modify('-1 day'), $event->end()->modify('+1 day'));
            $existing = $this->eventService
                ->getEventsInDateRange($dateRange, $coreUser)->all();
            $conflicts = $this->conflictService->findConflicts($event, $existing);
            $conflictList = $this->conflictService->formatConflicts($conflicts);

            if (\count($conflictList) > 0 && $conflictMode === 'block') {
                return ApiResponse::error(409, 'Event conflicts with existing events', array_map(
                    static fn (array $c): string => sprintf('%s (%s)', $c['title'], $c['start']),
                    $conflictList,
                ));
            }
        }

        // Check if user requires event approval
        if ($this->requiresApproval($user->getUserIdentifier())) {
            $event = new \WebCalendar\Core\Domain\Entity\Event(
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
                status: 'needs_approval',
                allDay: $event->isAllDay(),
            );
        }

        $this->eventService->createEvent($event, $coreUser);

        // Retrieve the created event to get the assigned ID
        // The core service saves the event; we need the generated ID.
        // Since Event is immutable with id=0, we search for it by UID.
        $created = $this->eventRepository->findByUid($event->uid());

        if ($created === null) {
            return ApiResponse::error(500, 'Event created but could not be retrieved');
        }

        // Assign categories if provided
        $categoryIds = $this->parseCategoryIds($data);
        if (\count($categoryIds) > 0) {
            $this->categoryService->assignToEvent(
                $created->id(),
                $user->getUserIdentifier(),
                $categoryIds,
                $coreUser,
            );
        }

        // Persist external (email-only) participants if provided
        if ($extParticipants !== []) {
            $this->extParticipants->saveForEvent($created->id()->value(), $extParticipants);
        }

        // Geocode location in background
        $geo = null;
        try {
            if ($this->geocodingService !== null) {
                $this->geocodingService->geocodeEvent($created->id()->value(), $created->location());
                $geo = $this->geoRepository->getCoordinates($created->id()->value());
            }
        } catch (\Throwable) {
        }

        $responseData = EventResponseDTO::fromEntity($created, $categoryIds, $geo);
        $responseData['ext_participants'] = $extParticipants;

        try {
            $this->mercure->publishEventCreated($created->id()->value(), $responseData);
        } catch (\Throwable) {
        }

        try {
            $this->webhookDispatcher->dispatch('event.created', $responseData);
        } catch (\Throwable) {
        }

        // Send invitations to external (email-only) participants
        try {
            $this->notifications->notifyExtParticipantsAdded($responseData, $extParticipants);
        } catch (\Throwable) {
        }

        // Log activity
        try {
            $this->activityLogService->log(
                $created->id()->value(),
                $user->getUserIdentifier(),
                null,
                ActivityLogType::CREATE,
                'Created event: ' . $created->name(),
            );
        } catch (\Throwable) {
        }

        $meta = \count($conflictList) > 0 ? ['conflicts' => $conflictList] : null;
        return ApiResponse::success($responseData, $meta, Response::HTTP_CREATED);
    }

    #[Route('/api/v2/events/{id}', name: 'api_events_get', methods: ['GET'])]
    public function get(int $id, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $event = $this->eventService->getEventById(new EventId($id));

        if ($event === null) {
            return ApiResponse::error(404, 'Event not found');
        }

        // Load category ids for this event. Previously this was
        // hardcoded to `[]`, which meant every GET /events/{id}
        // response reported the event as uncategorized even when
        // categories were assigned in the database — the Edit dialog
        // then showed no selected categories, making it look like
        // selecting a category in the dialog did nothing.
        $categoryIds = [];
        try {
            $categories = $this->categoryRepository->getForEvent(
                new EventId($id),
                $user->getUserIdentifier(),
            );
            foreach ($categories as $cat) {
                $categoryIds[] = $cat->id();
            }
        } catch (\Throwable) {
            // fall through — degrade to empty rather than 500
        }

        $geo = $this->geoRepository->getCoordinates($id);
        $response = EventResponseDTO::fromEntity($event, $categoryIds, $geo);

        // Include participants
        /** @var array<string, string> $participants */
        $participants = $this->eventRepository->getParticipantsWithStatus(new EventId($id));
        $response['participants'] = [];
        foreach ($participants as $login => $status) {
            $response['participants'][] = ['login' => $login, 'status' => $status];
        }

        // Include external (email-only) participants
        $response['ext_participants'] = $this->extParticipants->findForEvent($id);

        return ApiResponse::success($response);
    }

    /**
     * Update an event.
     *
     * Query params for recurring events:
     * - scope=all (default) — edit entire series
     * - scope=future&from_date=YYYYMMDD — split series at date, apply edits to new series
     */
    #[Route('/api/v2/events/{id}', name: 'api_events_update', methods: ['PUT'])]
    public function update(int $id, Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
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
            $fromDate = $this->parseDateParam($fromDateStr);
            if ($fromDate === null) {
                return ApiResponse::error(400, 'Missing or invalid from_date parameter (YYYYMMDD)');
            }

            $splitResult = $this->splitSeriesAtDate($id, $existing, $fromDate, $coreUser->login());
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
            if ($extParticipantsUpdate !== [] && $this->isExtParticipantsDisabled()) {
                return ApiResponse::error(403, 'External participants are disabled by the administrator');
            }
        }

        // Check for conflicts
        $conflictMode = $this->getConflictMode($user->getUserIdentifier());
        $conflictList = [];
        if ($conflictMode !== 'off') {
            $dateRange = new DateRange($updated->start()->modify('-1 day'), $updated->end()->modify('+1 day'));
            $allEvents = $this->eventService
                ->getEventsInDateRange($dateRange, $coreUser)->all();
            $conflicts = $this->conflictService->findConflicts($updated, $allEvents, $id);
            $conflictList = $this->conflictService->formatConflicts($conflicts);

            if (\count($conflictList) > 0 && $conflictMode === 'block') {
                return ApiResponse::error(409, 'Event conflicts with existing events', array_map(
                    static fn (array $c): string => sprintf('%s (%s)', $c['title'], $c['start']),
                    $conflictList,
                ));
            }
        }

        $this->eventService->updateEvent($updated, $coreUser);

        // Update categories if provided
        $categoryIds = $this->parseCategoryIds($data);
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
    #[Route('/api/v2/events/{id}', name: 'api_events_delete', methods: ['DELETE'])]
    public function delete(int $id, Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
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
            $date = $this->parseDateParam($dateStr);
            if ($date === null) {
                return ApiResponse::error(400, 'Missing or invalid date parameter (YYYYMMDD)');
            }

            if ($scope === 'occurrence') {
                return $this->cancelOccurrence($id, $existing, $date, $login);
            }

            return $this->cancelFutureOccurrences($id, $existing, $date, $login);
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

    /**
     * Restore (undo) a soft-deleted event or participant decline.
     */
    #[Route('/api/v2/events/{id}/restore', name: 'api_events_restore', methods: ['POST'])]
    public function restore(int $id, Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
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

    /**
     * Load access permissions the current user has been granted by other users.
     *
     * @param list<string> $otherUsers
     *
     * @return array<string, array{can_view: bool, can_edit: bool, see_time_only: bool}>
     */
    private function getAccessPermissions(string $currentUser, array $otherUsers): array
    {
        if ($otherUsers === []) {
            return [];
        }

        // Query: where other users have granted access to the current user
        $placeholders = [];
        $params = ['viewer' => $currentUser];
        foreach ($otherUsers as $i => $login) {
            $key = 'u' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $login;
        }

        $sql = 'SELECT cal_login, cal_can_view, cal_can_edit, cal_see_time_only
                FROM webcal_access_user
                WHERE cal_login IN (' . implode(', ', $placeholders) . ')
                AND cal_other_user = :viewer';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $result = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            if (\is_array($row)) {
                /** @var string $owner */
                $owner = $row['cal_login'];
                /** @var int|string $canView */
                $canView = $row['cal_can_view'] ?? 0;
                /** @var int|string $canEdit */
                $canEdit = $row['cal_can_edit'] ?? 0;
                $result[$owner] = [
                    'can_view' => (int) $canView > 0,
                    'can_edit' => (int) $canEdit > 0,
                    'see_time_only' => ($row['cal_see_time_only'] ?? 'N') === 'Y',
                ];
            }
        }

        return $result;
    }

    private function parseDateParam(string $dateStr): ?\DateTimeImmutable
    {
        if (\strlen($dateStr) !== 8 || !ctype_digit($dateStr)) {
            return null;
        }

        $formatted = sprintf(
            '%s-%s-%s',
            substr($dateStr, 0, 4),
            substr($dateStr, 4, 2),
            substr($dateStr, 6, 2),
        );

        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $formatted);

        return $dt === false ? null : $dt->setTime(0, 0);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<int>
     */
    private function parseCategoryIds(array $data): array
    {
        if (!isset($data['categories']) || !\is_array($data['categories'])) {
            return [];
        }

        $result = [];
        /** @var list<int|string> $catList */
        $catList = $data['categories'];
        foreach ($catList as $v) {
            if (is_numeric($v)) {
                $id = (int) $v;
                if ($id > 0) {
                    $result[] = $id;
                }
            }
        }

        return $result;
    }

    /**
     * Server-side enforcement of the DISABLE_EXT_PARTICIPANTS_FIELD feature
     * flag. The frontend hides the input when disabled, but we re-check here
     * so a direct API call can't bypass it.
     */
    private function isExtParticipantsDisabled(): bool
    {
        try {
            $value = $this->configService->getSetting('DISABLE_EXT_PARTICIPANTS_FIELD', 'N');
            return $value === 'Y';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Checks if a user requires admin approval for new events.
     */
    private function requiresApproval(string $login): bool
    {
        $prefs = $this->userRepository->getPreferences($login);
        foreach ($prefs as $pref) {
            if ($pref->key() === 'require_event_approval' && $pref->value() === 'Y') {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns the user's conflict detection mode preference.
     * Values: 'warn' (default), 'block', 'off'
     */
    private function getConflictMode(string $login): string
    {
        $prefs = $this->userRepository->getPreferences($login);
        foreach ($prefs as $pref) {
            if ($pref->key() === 'conflict_mode') {
                $value = $pref->value();
                if (\in_array($value, ['warn', 'block', 'off'], true)) {
                    return $value;
                }
            }
        }
        return 'warn';
    }

    /**
     * Cancel a single occurrence by adding an EXDATE.
     */
    private function cancelOccurrence(
        int $id,
        \WebCalendar\Core\Domain\Entity\Event $event,
        \DateTimeImmutable $date,
        string $actor,
    ): JsonResponse {
        $pdo = $this->pdoProvider->get();
        $dateInt = (int) $date->format('Ymd');

        // Insert EXDATE row (ignore if duplicate)
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO webcal_entry_repeats_not (cal_id, cal_date, cal_exdate) VALUES (:id, :date, 1)'
        );
        $stmt->execute(['id' => $id, 'date' => $dateInt]);

        // Bump sequence
        $pdo->prepare('UPDATE webcal_entry SET cal_sequence = cal_sequence + 1 WHERE cal_id = :id')
            ->execute(['id' => $id]);

        try {
            $this->mercure->publishEventUpdated($id, ['action' => 'exdate_added']);
        } catch (\Throwable) {
        }

        try {
            $this->activityLogService->log(
                $id,
                $actor,
                null,
                ActivityLogType::UPDATE,
                'Cancelled occurrence ' . $date->format('Y-m-d') . ' of: ' . $event->name(),
            );
        } catch (\Throwable) {
        }

        return new JsonResponse([
            'action' => 'occurrence_cancelled',
            'date' => $date->format('Y-m-d'),
        ]);
    }

    /**
     * Truncate a recurring series by setting UNTIL to the day before the given date.
     */
    private function cancelFutureOccurrences(
        int $id,
        \WebCalendar\Core\Domain\Entity\Event $event,
        \DateTimeImmutable $fromDate,
        string $actor,
    ): JsonResponse {
        $pdo = $this->pdoProvider->get();
        $untilDateInt = (int) $fromDate->modify('-1 day')->format('Ymd');

        // Set the cal_end of the recurrence rule
        $stmt = $pdo->prepare(
            'UPDATE webcal_entry_repeats SET cal_end = :until WHERE cal_id = :id'
        );
        $stmt->execute(['until' => $untilDateInt, 'id' => $id]);

        // Bump sequence
        $pdo->prepare('UPDATE webcal_entry SET cal_sequence = cal_sequence + 1 WHERE cal_id = :id')
            ->execute(['id' => $id]);

        try {
            $this->mercure->publishEventUpdated($id, ['action' => 'series_truncated']);
        } catch (\Throwable) {
        }

        try {
            $this->activityLogService->log(
                $id,
                $actor,
                null,
                ActivityLogType::UPDATE,
                'Truncated series at ' . $fromDate->format('Y-m-d') . ': ' . $event->name(),
            );
        } catch (\Throwable) {
        }

        return new JsonResponse([
            'action' => 'future_cancelled',
            'from_date' => $fromDate->format('Y-m-d'),
        ]);
    }

    /**
     * Split a recurring series at a given date.
     *
     * The original series is truncated with UNTIL = fromDate - 1 day.
     * A new recurring event is created starting at fromDate with the
     * modified data and the same recurrence pattern.
     *
     * @return array{original_id: int, new_id: int}
     */
    private function splitSeriesAtDate(
        int $originalId,
        \WebCalendar\Core\Domain\Entity\Event $original,
        \DateTimeImmutable $fromDate,
        string $actor,
    ): array {
        $pdo = $this->pdoProvider->get();

        // 1. Truncate original series
        $untilDateInt = (int) $fromDate->modify('-1 day')->format('Ymd');
        $pdo->prepare('UPDATE webcal_entry_repeats SET cal_end = :until WHERE cal_id = :id')
            ->execute(['until' => $untilDateInt, 'id' => $originalId]);
        $pdo->prepare('UPDATE webcal_entry SET cal_sequence = cal_sequence + 1 WHERE cal_id = :id')
            ->execute(['id' => $originalId]);

        // 2. Create new event starting at fromDate with same recurrence
        $newDateInt = (int) $fromDate->format('Ymd');
        $newUid = sprintf('%s-%s@split', $original->uid(), $fromDate->format('Ymd'));
        $now = $this->clock->now();

        // Get next ID
        $stmt = $pdo->query('SELECT COALESCE(MAX(cal_id), 0) + 1 FROM webcal_entry');
        /** @var int $newId */
        $newId = $stmt !== false ? (int) $stmt->fetchColumn() : 0;

        $pdo->prepare(
            'INSERT INTO webcal_entry (cal_id, cal_create_by, cal_date, cal_time, cal_duration,
             cal_name, cal_description, cal_location, cal_type, cal_access, cal_uid,
             cal_sequence, cal_status, cal_mod_date, cal_mod_time)
             VALUES (:id, :create_by, :date, :time, :duration, :name, :description, :location,
                     :type, :access, :uid, 0, NULL, :mod_date, :mod_time)'
        )->execute([
            'id' => $newId,
            'create_by' => $original->createdBy(),
            'date' => $newDateInt,
            'time' => (int) $original->start()->format('His'),
            'duration' => $original->duration(),
            'name' => $original->name(),
            'description' => $original->description(),
            'location' => $original->location(),
            'type' => 'M',
            'access' => $original->access()->value,
            'uid' => $newUid,
            'mod_date' => (int) $now->format('Ymd'),
            'mod_time' => (int) $now->format('His'),
        ]);

        // Copy recurrence rule (without the UNTIL we just set on the original)
        $rruleRow = $pdo->prepare(
            'SELECT * FROM webcal_entry_repeats WHERE cal_id = :id'
        );
        $rruleRow->execute(['id' => $originalId]);
        /** @var array<string, mixed>|false $rr */
        $rr = $rruleRow->fetch(\PDO::FETCH_ASSOC);
        if (\is_array($rr)) {
            $rr['cal_id'] = $newId;
            $rr['cal_end'] = $original->recurrence()->rule() !== null
                ? ($rr['cal_end'] ?? null)  // Preserve original end if it existed before truncation
                : null;
            // The original UNTIL was just set; the new series should use the ORIGINAL end
            // Since we don't have it anymore, use NULL (no end) — user can edit the new series
            $rr['cal_end'] = null;

            $cols = array_keys($rr);
            $placeholders = array_map(static fn (string $c) => ':' . $c, $cols);
            $pdo->prepare(
                'INSERT INTO webcal_entry_repeats (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $placeholders) . ')'
            )->execute($rr);
        }

        // Add creator as participant
        $pdo->prepare(
            "INSERT INTO webcal_entry_user (cal_id, cal_login, cal_status) VALUES (:id, :login, 'A')"
        )->execute(['id' => $newId, 'login' => $original->createdBy()]);

        try {
            $this->activityLogService->log(
                $originalId,
                $actor,
                null,
                ActivityLogType::UPDATE,
                'Split series at ' . $fromDate->format('Y-m-d') . ': ' . $original->name() . ' → new event #' . $newId,
            );
        } catch (\Throwable) {
        }

        return ['original_id' => $originalId, 'new_id' => $newId];
    }
}
