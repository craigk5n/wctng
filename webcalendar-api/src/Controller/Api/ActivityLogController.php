<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use WebCalendar\Core\Application\Service\ActivityLogService;
use WebCalendar\Core\Domain\ValueObject\DateRange;

final class ActivityLogController
{
    public function __construct(
        private readonly ActivityLogService $activityLogService,
        private readonly ClockInterface $clock = new NativeClock(),
    ) {
    }

    #[Route('/api/v2/admin/activity-log', name: 'api_admin_activity_log', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null || !$user->getCoreUser()->isAdmin()) {
            return ApiResponse::error(403, 'Admin access required');
        }

        $startStr = $request->query->getString('start', '');
        $endStr = $request->query->getString('end', '');

        // Default to last 30 days if no range specified
        if ($startStr === '' || $endStr === '') {
            $end = $this->clock->now();
            $start = $end->modify('-30 days');
        } else {
            $start = \DateTimeImmutable::createFromFormat('Ymd', $startStr);
            $end = \DateTimeImmutable::createFromFormat('Ymd', $endStr);
            if ($start === false || $end === false) {
                return ApiResponse::error(400, 'Invalid date format. Expected YYYYMMDD.');
            }
            $start = $start->setTime(0, 0);
            $end = $end->setTime(23, 59, 59);
        }

        $loginFilter = $request->query->getString('user', '') ?: null;

        $range = new DateRange($start, $end);
        $entries = $this->activityLogService->getLogs($range, $loginFilter);

        $page = max(1, $request->query->getInt('page', 1));
        $limit = min(100, max(1, $request->query->getInt('limit', 50)));
        $total = \count($entries);
        $offset = ($page - 1) * $limit;
        $pageItems = \array_slice($entries, $offset, $limit);

        $items = array_map(static function ($entry): array {
            $typeLabels = [
                'C' => 'create',
                'A' => 'approve',
                'R' => 'reject',
                'U' => 'update',
                'M' => 'notification',
                'E' => 'reminder',
                'X' => 'extra',
            ];

            return [
                'id' => $entry->id(),
                'entry_id' => $entry->entryId(),
                'user' => $entry->login(),
                'user_cal' => $entry->userCal(),
                'action' => $typeLabels[$entry->type()->value],
                'action_code' => $entry->type()->value,
                'timestamp' => $entry->date()->format('Y-m-d\TH:i:s'),
                'text' => $entry->text(),
            ];
        }, $pageItems);

        return ApiResponse::paginated(array_values($items), $total, $page, $limit);
    }
}
