<?php

declare(strict_types=1);

namespace App\Controller\Api\Event;

use App\DTO\EventResponseDTO;
use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use App\Service\ExtParticipantRepository;
use App\Service\GeoRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use WebCalendar\Core\Application\Service\EventService;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Infrastructure\Persistence\PdoCategoryRepository;

final class GetEventController
{
    public function __construct(
        private readonly EventService $eventService,
        private readonly PdoCategoryRepository $categoryRepository,
        private readonly GeoRepository $geoRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly ExtParticipantRepository $extParticipants,
    ) {}

    #[Route('/api/v2/events/{id}', name: 'api_events_get', methods: ['GET'])]
    public function __invoke(int $id, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
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
}
