<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Poll\PollRepository;
use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use WebCalendar\Core\Application\Service\EventService;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;

final class PollController
{
    private const MIN_OPTIONS = 2;

    /** What the voting page offers, and the only three yes_count can read. */
    private const VOTE_VALUES = ['yes', 'maybe', 'no'];

    public function __construct(
        private readonly PollRepository $pollRepo,
        private readonly EventService $eventService,
    ) {}

    #[Route('/api/v2/polls', name: 'api_polls_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        /** @var mixed $decoded */
        $decoded = json_decode($request->getContent(), true);
        /** @var array<string, mixed> $data */
        $data = \is_array($decoded) ? $decoded : [];

        $title = isset($data['title']) && \is_string($data['title']) ? $data['title'] : '';
        if ($title === '') {
            return ApiResponse::error(400, 'Missing required field: title');
        }

        $options = $data['options'] ?? null;
        if (!\is_array($options) || \count($options) < self::MIN_OPTIONS) {
            return ApiResponse::error(400, 'At least 2 time options required');
        }

        // Checked before anything is stored: createPoll() inserts the poll and
        // then the options one at a time, so a slot MySQL refuses as a DATETIME
        // leaves a poll behind with fewer options than were asked for.
        $slots = [];
        /** @var mixed $option */
        foreach ($options as $option) {
            $slot = self::readSlot($option);
            if ($slot === null) {
                return ApiResponse::error(
                    400,
                    'Each time option needs a start and an end, and must end after it starts.',
                );
            }
            $slots[] = $slot;
        }

        $description = isset($data['description']) && \is_string($data['description']) ? $data['description'] : '';

        $pollId = $this->pollRepo->createPoll(
            $user->getUserIdentifier(),
            $title,
            $description,
            $slots,
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

        /** @var mixed $decoded */
        $decoded = json_decode($request->getContent(), true);
        /** @var array<string, mixed> $data */
        $data = \is_array($decoded) ? $decoded : [];
        $votes = $data['votes'] ?? null;

        if (!\is_array($votes) || \count($votes) === 0) {
            return ApiResponse::error(400, 'No votes provided');
        }

        /** @var list<int> $optionIds */
        $optionIds = array_column($poll['options'], 'id');

        $checked = [];
        foreach ($votes as $optionId => $vote) {
            // castVotes() writes the ids it is handed, and clears only the ones
            // belonging to this poll. An id from another poll therefore lands
            // there, where no one voting on that poll can ever clear it again --
            // a permanent entry in the ballot that decides its winning slot.
            if (!\in_array((int) $optionId, $optionIds, true)) {
                return ApiResponse::error(400, 'Vote names an option that is not in this poll');
            }

            // yes_count matches on the exact string, so anything else is a vote
            // that was cast and a vote that does not exist at the same time.
            if (!\is_string($vote) || !\in_array($vote, self::VOTE_VALUES, true)) {
                return ApiResponse::error(400, 'Vote must be one of: ' . implode(', ', self::VOTE_VALUES));
            }

            $checked[(int) $optionId] = $vote;
        }

        $this->pollRepo->castVotes($id, $user->getUserIdentifier(), $checked);

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

        $this->eventService->createEvent($event, $coreUser);
        $this->pollRepo->closePoll($id);

        return ApiResponse::success([
            'poll_id' => $id,
            'winning_option' => $bestOption,
            'event_created' => true,
        ]);
    }

    /**
     * One time option, in the shape the DATETIME columns take.
     *
     * @return array{start: string, end: string}|null null when it is not a
     *   usable slot at all
     */
    private static function readSlot(mixed $option): ?array
    {
        if (!\is_array($option)) {
            return null;
        }

        $startText = $option['start'] ?? null;
        $endText = $option['end'] ?? null;

        // An empty string is not a missing value to DateTimeImmutable -- it
        // reads as "now" -- so it is rejected here rather than stored as
        // whenever the poll happened to be created.
        if (!\is_string($startText) || !\is_string($endText) || $startText === '' || $endText === '') {
            return null;
        }

        try {
            $start = new \DateTimeImmutable($startText);
            $end = new \DateTimeImmutable($endText);
        } catch (\Exception) {
            return null;
        }

        if ($end <= $start) {
            return null;
        }

        // MySQL reads this shape and refuses what ISO 8601 offers.
        return ['start' => $start->format('Y-m-d H:i:s'), 'end' => $end->format('Y-m-d H:i:s')];
    }
}
