<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\DTO\EventRequestDTO;
use App\DTO\EventResponseDTO;
use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use App\Service\ConflictDetectionService;
use App\Service\CoreServiceFactory;
use App\Service\DescriptionSanitizer;
use App\Service\EventNotificationService;
use App\Service\ExtParticipantRepository;
use App\Service\ExtParticipantValidator;
use App\Service\GeocodingService;
use App\Service\GeoRepository;
use App\Service\MercurePublisher;
use App\Webhook\WebhookDispatcher;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use WebCalendar\Core\Domain\ValueObject\ActivityLogType;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventId;

final class EventController
{
    private readonly GeoRepository $geoRepository;
    private readonly ExtParticipantRepository $extParticipants;
    private readonly ExtParticipantValidator $extParticipantValidator;

    public function __construct(
        private readonly CoreServiceFactory $coreServiceFactory,
        private readonly MercurePublisher $mercure,
        private readonly \PDO $pdo,
        private readonly EventNotificationService $notifications,
        private readonly WebhookDispatcher $webhookDispatcher,
        private readonly DescriptionSanitizer $descriptionSanitizer = new DescriptionSanitizer(),
        private readonly ConflictDetectionService $conflictService = new ConflictDetectionService(),
        private readonly ?GeocodingService $geocodingService = null,
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
        $limit = min(100, max(1, $request->query->getInt('limit', 20)));

        $dateRange = new DateRange($start, $end);
        $coreUser = $user->getCoreUser();
        $collection = $this->coreServiceFactory->getEventService()->getEventsInDateRange($dateRange, $coreUser);

        $allEvents = $collection->all();

        // Include events from active layers when layers=1
        if ($request->query->getString('layers', '') === '1') {
            $layers = $this->coreServiceFactory->getLayerService()->getLayersForUser($user->getUserIdentifier());
            $layerUsers = array_map(static fn ($l) => $l->layerUser(), $layers);
            if (\count($layerUsers) > 0) {
                // Load access permissions for layered users
                $accessMap = $this->getAccessPermissions($user->getUserIdentifier(), array_values($layerUsers));

                $layerCollection = $this->coreServiceFactory->getEventService()->getEventsInDateRange($dateRange, null, null, $layerUsers);
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
            $categoryRepo = $this->coreServiceFactory->getCategoryRepository();
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
        $existing = $this->coreServiceFactory->getEventService()
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

        $coreUser = $user->getCoreUser();

        // Check for conflicts
        $conflictMode = $this->getConflictMode($user->getUserIdentifier());
        $conflictList = [];
        if ($conflictMode !== 'off') {
            $dateRange = new DateRange($event->start()->modify('-1 day'), $event->end()->modify('+1 day'));
            $existing = $this->coreServiceFactory->getEventService()
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

        $this->coreServiceFactory->getEventService()->createEvent($event, $coreUser);

        // Retrieve the created event to get the assigned ID
        // The core service saves the event; we need the generated ID.
        // Since Event is immutable with id=0, we search for it by UID.
        $created = $this->coreServiceFactory->getEventRepository()->findByUid($event->uid());

        if ($created === null) {
            return ApiResponse::error(500, 'Event created but could not be retrieved');
        }

        // Assign categories if provided
        $categoryIds = $this->parseCategoryIds($data);
        if (\count($categoryIds) > 0) {
            $this->coreServiceFactory->getCategoryService()->assignToEvent(
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

        // Log activity
        try {
            $this->coreServiceFactory->getActivityLogService()->log(
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

        $event = $this->coreServiceFactory->getEventService()->getEventById(new EventId($id));

        if ($event === null) {
            return ApiResponse::error(404, 'Event not found');
        }

        $geo = $this->geoRepository->getCoordinates($id);
        $response = EventResponseDTO::fromEntity($event, [], $geo);

        // Include participants
        /** @var array<string, string> $participants */
        $participants = $this->coreServiceFactory->getEventRepository()->getParticipantsWithStatus(new EventId($id));
        $response['participants'] = [];
        foreach ($participants as $login => $status) {
            $response['participants'][] = ['login' => $login, 'status' => $status];
        }

        // Include external (email-only) participants
        $response['ext_participants'] = $this->extParticipants->findForEvent($id);

        return ApiResponse::success($response);
    }

    #[Route('/api/v2/events/{id}', name: 'api_events_update', methods: ['PUT'])]
    public function update(int $id, Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $existing = $this->coreServiceFactory->getEventService()->getEventById(new EventId($id));

        if ($existing === null) {
            return ApiResponse::error(404, 'Event not found');
        }

        $coreUser = $user->getCoreUser();

        // Check ownership (only owner or admin can update)
        if ($existing->createdBy() !== $coreUser->login() && !$coreUser->isAdmin()) {
            return ApiResponse::error(403, 'You do not have permission to update this event');
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
        }

        // Check for conflicts
        $conflictMode = $this->getConflictMode($user->getUserIdentifier());
        $conflictList = [];
        if ($conflictMode !== 'off') {
            $dateRange = new DateRange($updated->start()->modify('-1 day'), $updated->end()->modify('+1 day'));
            $allEvents = $this->coreServiceFactory->getEventService()
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

        $this->coreServiceFactory->getEventService()->updateEvent($updated, $coreUser);

        // Update categories if provided
        $categoryIds = $this->parseCategoryIds($data);
        if (isset($data['categories'])) {
            $this->coreServiceFactory->getCategoryService()->assignToEvent(
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
        $saved = $this->coreServiceFactory->getEventService()->getEventById(new EventId($id));

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
            $participants = $this->coreServiceFactory->getEventRepository()->getParticipantsWithStatus(new EventId($id));
            $pList = [];
            foreach ($participants as $login => $status) {
                $pList[] = ['login' => $login, 'status' => $status];
            }
            $this->notifications->notifyEventUpdated($responseData, $pList);
        } catch (\Throwable) {
        }

        // Log activity
        try {
            $this->coreServiceFactory->getActivityLogService()->log(
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

    #[Route('/api/v2/events/{id}', name: 'api_events_delete', methods: ['DELETE'])]
    public function delete(int $id, #[CurrentUser] ?WebCalendarUser $user): Response
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $existing = $this->coreServiceFactory->getEventService()->getEventById(new EventId($id));

        if ($existing === null) {
            return ApiResponse::error(404, 'Event not found');
        }

        $coreUser = $user->getCoreUser();

        if ($existing->createdBy() !== $coreUser->login() && !$coreUser->isAdmin()) {
            return ApiResponse::error(403, 'You do not have permission to delete this event');
        }

        // Notify participants before deleting
        try {
            /** @var array<string, string> $participants */
            $participants = $this->coreServiceFactory->getEventRepository()->getParticipantsWithStatus(new EventId($id));
            $pList = [];
            foreach ($participants as $login => $status) {
                $pList[] = ['login' => $login, 'status' => $status];
            }
            $this->notifications->notifyEventDeleted($existing->name(), $pList);
        } catch (\Throwable) {
        }

        $this->coreServiceFactory->getEventService()->deleteEvent(new EventId($id), $coreUser);

        // Core EventRepository::delete() does not cascade to
        // webcal_entry_ext_user — clean it up here so rows don't orphan.
        $this->extParticipants->deleteForEvent($id);

        try {
            $this->mercure->publishEventDeleted($id);
        } catch (\Throwable) {
        }

        try {
            $this->webhookDispatcher->dispatch('event.deleted', ['id' => $id]);
        } catch (\Throwable) {
        }

        // Log activity
        try {
            $this->coreServiceFactory->getActivityLogService()->log(
                $id,
                $user->getUserIdentifier(),
                null,
                ActivityLogType::UPDATE,
                'Deleted event: ' . $existing->name(),
            );
        } catch (\Throwable) {
        }

        return ApiResponse::noContent();
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
     * Checks if a user requires admin approval for new events.
     */
    private function requiresApproval(string $login): bool
    {
        $prefs = $this->coreServiceFactory->getUserRepository()->getPreferences($login);
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
        $prefs = $this->coreServiceFactory->getUserRepository()->getPreferences($login);
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
}
