<?php

declare(strict_types=1);

namespace App\Controller\Api\Event;

use App\DTO\EventResponseDTO;
use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use App\Service\AccessPermissionRepository;
use App\Service\EventInputParser;
use App\Service\GeoRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use WebCalendar\Core\Application\Service\ConfigService;
use WebCalendar\Core\Application\Service\EventService;
use WebCalendar\Core\Application\Service\LayerService;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Infrastructure\Persistence\PdoCategoryRepository;

final class ListEventsController
{
    public function __construct(
        private readonly EventService $eventService,
        private readonly LayerService $layerService,
        private readonly AccessPermissionRepository $accessPerms,
        private readonly PdoCategoryRepository $categoryRepository,
        private readonly GeoRepository $geoRepository,
        private readonly ConfigService $configService,
    ) {}

    #[Route('/api/v2/events', name: 'api_events_list', methods: ['GET'])]
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
            $layerUsers = array_map(static fn($l) => $l->layerUser(), $layers);
            if (\count($layerUsers) > 0) {
                // Load access permissions for layered users
                $accessMap = $this->accessPerms->findGrantsFor($user->getUserIdentifier(), array_values($layerUsers));

                $layerCollection = $this->eventService->getEventsInDateRange($dateRange, null, null, $layerUsers);
                // Merge, avoiding duplicates by event ID, filtering by access
                $existingIds = array_map(static fn($e) => $e->id(), $allEvents);
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
        $eventIds = array_map(static fn($e) => $e->id(), $pageItems);
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
            array_map(static fn($e) => $e->id()->value(), $pageItems),
        );

        $items = array_map(
            static fn(\WebCalendar\Core\Domain\Entity\Event $event): array => EventResponseDTO::fromEntity(
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
}
