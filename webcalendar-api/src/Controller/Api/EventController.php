<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\DTO\EventRequestDTO;
use App\DTO\EventResponseDTO;
use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use App\Service\CoreServiceFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventId;

final class EventController
{
    public function __construct(
        private readonly CoreServiceFactory $coreServiceFactory,
    ) {
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
        $total = \count($allEvents);
        $offset = ($page - 1) * $limit;
        $pageItems = \array_slice($allEvents, $offset, $limit);

        $items = EventResponseDTO::fromCollection(array_values($pageItems));

        return ApiResponse::paginated($items, $total, $page, $limit);
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

        try {
            $event = EventRequestDTO::toEntity($data, $user->getUserIdentifier());
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error(400, $e->getMessage());
        }

        $coreUser = $user->getCoreUser();
        $this->coreServiceFactory->getEventService()->createEvent($event, $coreUser);

        // Retrieve the created event to get the assigned ID
        // The core service saves the event; we need the generated ID.
        // Since Event is immutable with id=0, we search for it by UID.
        $created = $this->coreServiceFactory->getEventRepository()->findByUid($event->uid());

        if ($created === null) {
            return ApiResponse::error(500, 'Event created but could not be retrieved');
        }

        return ApiResponse::success(EventResponseDTO::fromEntity($created), null, Response::HTTP_CREATED);
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

        return ApiResponse::success(EventResponseDTO::fromEntity($event));
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

        try {
            $updated = EventRequestDTO::applyUpdate($data, $existing);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error(400, $e->getMessage());
        }

        $this->coreServiceFactory->getEventService()->updateEvent($updated, $coreUser);

        // Re-fetch to return the saved state
        $saved = $this->coreServiceFactory->getEventService()->getEventById(new EventId($id));

        if ($saved === null) {
            return ApiResponse::error(500, 'Event updated but could not be retrieved');
        }

        return ApiResponse::success(EventResponseDTO::fromEntity($saved));
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

        $this->coreServiceFactory->getEventService()->deleteEvent(new EventId($id), $coreUser);

        return ApiResponse::noContent();
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
}
