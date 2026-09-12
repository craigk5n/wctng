<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use WebCalendar\Core\Application\Service\ActivityLogService;
use WebCalendar\Core\Domain\ValueObject\DateRange;

final class ActivityLogController
{
    public function __construct(
        private readonly ActivityLogService $activityLogService,
        private readonly ClockInterface $clock = new NativeClock(),
    ) {}

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
            $start = self::readDate($startStr);
            $end = self::readDate($endStr);
            if ($start === null || $end === null) {
                return ApiResponse::error(400, 'Invalid date format. Expected YYYYMMDD.');
            }
            $start = $start->setTime(0, 0);
            $end = $end->setTime(23, 59, 59);

            // DateRange refuses this pair, and nothing here caught it, so a
            // range entered the wrong way round took the whole page down with
            // an uncaught InvalidArgumentException.
            if ($start > $end) {
                return ApiResponse::error(400, 'The start date must not be after the end date.');
            }
        }

        // Not `?: null`: that reads the account named "0" as nobody named,
        // which answers the narrowest possible question with everybody's
        // activity.
        $loginRaw = $request->query->getString('user', '');
        $loginFilter = $loginRaw === '' ? null : $loginRaw;

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

    /**
     * createFromFormat rolls an impossible date forward rather than refusing
     * it -- 20260231 comes back as the 3rd of March, and 20261345 as the 14th
     * of February the year after. An audit trail that answers for a day nobody
     * asked about, and says nothing about having done so, is worse than one
     * that refuses. The round trip is what separates a date from a
     * date-shaped string.
     */
    private static function readDate(string $value): ?\DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('Ymd', $value);

        if ($parsed === false || $parsed->format('Ymd') !== $value) {
            return null;
        }

        return $parsed;
    }
}
