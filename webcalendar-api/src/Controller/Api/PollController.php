<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Poll\PollRepository;
use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use App\Service\CoreServiceFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;

final class PollController
{
    private readonly PollRepository $pollRepo;

    public function __construct(\PDO $pdo, private readonly CoreServiceFactory $factory)
    {
        $this->pollRepo = new PollRepository($pdo);
    }

    #[Route('/api/v2/polls', name: 'api_polls_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        /** @var array{title?: string, description?: string, options?: list<array{start: string, end: string}>, participants?: list<string>} $data */
        $data = json_decode((string) $request->getContent(), true) ?? [];

        $title = $data['title'] ?? '';
        if ($title === '') {
            return ApiResponse::error(400, 'Missing required field: title');
        }

        $options = $data['options'] ?? [];
        if (\count($options) < 2) {
            return ApiResponse::error(400, 'At least 2 time options required');
        }

        $pollId = $this->pollRepo->createPoll(
            $user->getUserIdentifier(),
            $title,
            $data['description'] ?? '',
            $options,
        );

        $poll = $this->pollRepo->getPoll($pollId);

        return ApiResponse::success($poll, null, 201);
    }

    #[Route('/api/v2/polls', name: 'api_polls_list', methods: ['GET'])]
    public function list(#[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $polls = $this->pollRepo->listByCreator($user->getUserIdentifier());
        return ApiResponse::success($polls);
    }

    #[Route('/api/v2/polls/{id}', name: 'api_polls_get', methods: ['GET'])]
    public function get(int $id, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $poll = $this->pollRepo->getPoll($id);
        if ($poll === null) {
            return ApiResponse::error(404, 'Poll not found');
        }

        return ApiResponse::success($poll);
    }

    #[Route('/api/v2/polls/{id}/vote', name: 'api_polls_vote', methods: ['POST'])]
    public function vote(int $id, Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $poll = $this->pollRepo->getPoll($id);
        if ($poll === null) {
            return ApiResponse::error(404, 'Poll not found');
        }

        if ($poll['status'] !== 'open') {
            return ApiResponse::error(400, 'Poll is closed');
        }

        /** @var array{votes?: array<int, string>} $data */
        $data = json_decode((string) $request->getContent(), true) ?? [];
        $votes = $data['votes'] ?? [];

        if (\count($votes) === 0) {
            return ApiResponse::error(400, 'No votes provided');
        }

        $this->pollRepo->castVotes($id, $user->getUserIdentifier(), $votes);

        $updated = $this->pollRepo->getPoll($id);
        return ApiResponse::success($updated);
    }

    #[Route('/api/v2/polls/{id}/finalize', name: 'api_polls_finalize', methods: ['POST'])]
    public function finalize(int $id, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $poll = $this->pollRepo->getPoll($id);
        if ($poll === null) {
            return ApiResponse::error(404, 'Poll not found');
        }

        if ($poll['creator'] !== $user->getUserIdentifier()) {
            return ApiResponse::error(403, 'Only the poll creator can finalize');
        }

        if ($poll['status'] !== 'open') {
            return ApiResponse::error(400, 'Poll is already closed');
        }

        // Find winning option (most yes votes)
        $bestOption = null;
        $bestCount = -1;
        foreach ($poll['options'] as $opt) {
            if ($opt['yes_count'] > $bestCount) {
                $bestCount = $opt['yes_count'];
                $bestOption = $opt;
            }
        }

        if ($bestOption === null) {
            return ApiResponse::error(400, 'No options available');
        }

        // Create event from winning option
        $start = new \DateTimeImmutable($bestOption['start']);
        $end = new \DateTimeImmutable($bestOption['end']);
        $duration = (int) (($end->getTimestamp() - $start->getTimestamp()) / 60);

        $coreUser = $user->getCoreUser();
        $event = new Event(
            id: new EventId(0),
            uid: sprintf('poll-%d@webcalendar', $id),
            name: $poll['title'],
            description: $poll['description'],
            location: '',
            start: $start,
            duration: $duration,
            createdBy: $user->getUserIdentifier(),
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
        );

        $this->factory->getEventService()->createEvent($event, $coreUser);
        $this->pollRepo->closePoll($id);

        return ApiResponse::success([
            'poll_id' => $id,
            'winning_option' => $bestOption,
            'event_created' => true,
        ]);
    }
}
