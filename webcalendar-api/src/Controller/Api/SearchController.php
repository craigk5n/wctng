<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\DTO\EventResponseDTO;
use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use App\Service\CoreServiceFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use WebCalendar\Core\Domain\ValueObject\DateRange;

final class SearchController
{
    public function __construct(
        private readonly CoreServiceFactory $coreServiceFactory,
    ) {
    }

    #[Route('/api/v2/search', name: 'api_search', methods: ['GET'])]
    public function search(Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $keyword = $request->query->getString('q', '');
        if ($keyword === '') {
            return ApiResponse::error(400, 'Missing required query param: q');
        }

        $startStr = $request->query->getString('start', '');
        $endStr = $request->query->getString('end', '');

        // Default range: 1 year back to 1 year forward
        $start = $startStr !== '' ? self::parseDate($startStr) : new \DateTimeImmutable('-1 year');
        $end = $endStr !== '' ? self::parseDate($endStr) : new \DateTimeImmutable('+1 year');

        if ($start === null || $end === null) {
            return ApiResponse::error(400, 'Invalid date format');
        }

        $range = new DateRange($start, $end);
        $coreUser = $user->getCoreUser();

        // Get all events in range, then filter by keyword in PHP
        // (workaround for core's search SQL parameter reuse bug)
        $collection = $this->coreServiceFactory->getEventService()->getEventsInDateRange($range, $coreUser);

        $keywordLower = mb_strtolower($keyword);
        $matches = [];
        foreach ($collection->all() as $event) {
            $titleMatch = str_contains(mb_strtolower($event->name()), $keywordLower);
            $descMatch = str_contains(mb_strtolower($event->description()), $keywordLower);
            if ($titleMatch || $descMatch) {
                $matches[] = $event;
            }
        }

        $items = EventResponseDTO::fromCollection($matches);

        return ApiResponse::success($items);
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
