<?php

declare(strict_types=1);

namespace App\Controller\Api\Event;

use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use App\Service\ConflictDetectionService;
use App\Service\EventInputParser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use WebCalendar\Core\Application\Service\EventService;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventId;

final class ConflictsController
{
    public function __construct(
        private readonly EventService $eventService,
        private readonly ConflictDetectionService $conflictService,
    ) {}

    #[Route('/api/v2/events/conflicts', name: 'api_events_conflicts', methods: ['GET'])]
    public function __invoke(Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $startStr = $request->query->getString('start', '');
        $endStr = $request->query->getString('end', '');

        if ($startStr === '' || $endStr === '') {
            return ApiResponse::error(400, 'Missing required query params: start, end (YYYYMMDD)');
        }

        $start = EventInputParser::parseDateParam($startStr);
        $end = EventInputParser::parseDateParam($endStr);

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
}
