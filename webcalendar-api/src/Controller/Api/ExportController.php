<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use App\Service\CoreServiceFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use WebCalendar\Core\Domain\ValueObject\DateRange;

final class ExportController
{
    public function __construct(
        private readonly CoreServiceFactory $coreServiceFactory,
    ) {
    }

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

        $start = self::parseDate($startStr);
        $end = self::parseDate($endStr);

        if ($start === null || $end === null) {
            return ApiResponse::error(400, 'Invalid date format');
        }

        $coreUser = $user->getCoreUser();
        $range = new DateRange($start, $end);

        $collection = $this->coreServiceFactory->getEventService()->getEventsInDateRange($range, $coreUser);

        $icsContent = $this->coreServiceFactory->getExportService()->exportIcal($collection);

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

    private static function parseDate(string $dateStr): ?\DateTimeImmutable
    {
        if (\strlen($dateStr) !== 8 || !ctype_digit($dateStr)) {
            return null;
        }

        $formatted = sprintf('%s-%s-%s', substr($dateStr, 0, 4), substr($dateStr, 4, 2), substr($dateStr, 6, 2));
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $formatted);

        return $dt === false ? null : $dt->setTime(0, 0);
    }
}
