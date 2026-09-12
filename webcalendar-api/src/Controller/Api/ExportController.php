<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use App\Service\EventInputParser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use WebCalendar\Core\Application\Service\EventService;
use WebCalendar\Core\Application\Service\ExportService;
use WebCalendar\Core\Domain\ValueObject\DateRange;

final class ExportController
{
    public function __construct(
        private readonly EventService $eventService,
        private readonly ExportService $exportService,
    ) {}

    #[Route('/api/v2/export', name: 'api_export', methods: ['GET'])]
    public function export(Request $request, #[CurrentUser] ?WebCalendarUser $user): Response
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $startStr = $request->query->getString('start', '');
        $endStr = $request->query->getString('end', '');

        if ($startStr === '' || $endStr === '') {
            return ApiResponse::error(400, 'Missing required query params: start, end (YYYYMMDD)');
        }

        // The private copy this used to carry left out the round-trip check
        // that EventInputParser makes, so createFromFormat rolled a date that
        // does not exist forward to one that does: 20260231 exported March
        // under a file named for February. The filename echoes these two
        // parameters verbatim, so the wrong month arrived correctly labelled.
        $start = EventInputParser::parseDateParam($startStr);
        $end = EventInputParser::parseDateParam($endStr);

        if ($start === null || $end === null) {
            return ApiResponse::error(400, 'Invalid date format');
        }

        // DateRange throws when these arrive the wrong way round, and nothing
        // catches it here -- a 500 out of an endpoint that answers in JSON.
        if ($start > $end) {
            return ApiResponse::error(400, 'start must not be after end');
        }

        $coreUser = $user->getCoreUser();
        $range = new DateRange($start, $end);

        $collection = $this->eventService->getEventsInDateRange($range, $coreUser);

        $icsContent = $this->exportService->exportIcal($collection);

        $response = new Response($icsContent, Response::HTTP_OK, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => sprintf(
                'attachment; filename="webcalendar-%s-to-%s.ics"',
                $startStr,
                $endStr,
            ),
        ]);

        return $response;
    }
}
