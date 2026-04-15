<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use App\Service\DescriptionSanitizer;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use WebCalendar\Core\Application\Service\JournalService;
use WebCalendar\Core\Domain\Entity\Journal;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;

final class JournalController
{
    public function __construct(
        private readonly JournalService $journalService,
        private readonly DescriptionSanitizer $descriptionSanitizer = new DescriptionSanitizer(),
        private readonly ClockInterface $clock = new NativeClock(),
    ) {
    }

    #[Route('/api/v2/journals', name: 'api_journals_list', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $startStr = $request->query->getString('start', '');
        $endStr = $request->query->getString('end', '');

        if ($startStr === '' || $endStr === '') {
            return ApiResponse::error(400, 'Missing required query params: start, end');
        }

        $start = self::parseDate($startStr);
        $end = self::parseDate($endStr);
        if ($start === null || $end === null) {
            return ApiResponse::error(400, 'Invalid date format');
        }

        $journals = $this->journalService->getJournalsInDateRange(
            new DateRange($start, $end),
            $user->getUserIdentifier(),
        );

        $items = array_map(self::journalToArray(...), $journals);

        return ApiResponse::success(array_values($items));
    }

    #[Route('/api/v2/journals', name: 'api_journals_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $decoded = json_decode($request->getContent(), true);
        if (!\is_array($decoded)) {
            return ApiResponse::error(400, 'Invalid JSON body');
        }

        /** @var array<string, mixed> $data */
        $data = $decoded;

        $title = isset($data['title']) && \is_string($data['title']) ? $data['title'] : null;
        if ($title === null || $title === '') {
            return ApiResponse::error(400, 'Missing required field: title');
        }

        $dateStr = isset($data['date']) && \is_string($data['date']) ? $data['date'] : null;
        $date = $dateStr !== null ? self::parseDate($dateStr) : $this->clock->now();

        if ($date === null) {
            return ApiResponse::error(400, 'Invalid date format');
        }

        $text = isset($data['text']) && \is_string($data['text'])
            ? $this->descriptionSanitizer->sanitize($data['text'])
            : '';
        $uid = sprintf('wctng-journal-%s@webcalendar', bin2hex(random_bytes(16)));

        $journal = new Journal(
            id: new EventId(0),
            uid: $uid,
            name: $title,
            description: $text,
            location: '',
            start: $date,
            duration: 0,
            createdBy: $user->getUserIdentifier(),
            type: EventType::JOURNAL,
            access: AccessLevel::PUBLIC,
            allDay: true,
        );

        $this->journalService->createJournal($journal, $user->getCoreUser());

        // Find created journal by searching in date range
        $range = new DateRange($date->modify('-1 day'), $date->modify('+1 day'));
        $journals = $this->journalService->getJournalsInDateRange(
            $range,
            $user->getUserIdentifier(),
        );

        $created = null;
        foreach ($journals as $j) {
            if ($j->uid() === $uid) {
                $created = $j;
                break;
            }
        }

        if ($created === null) {
            return ApiResponse::error(500, 'Journal created but could not be retrieved');
        }

        return ApiResponse::success(self::journalToArray($created), null, Response::HTTP_CREATED);
    }

    #[Route('/api/v2/journals/{id}', name: 'api_journals_get', methods: ['GET'])]
    public function get(int $id, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $journal = $this->journalService->getJournalById(new EventId($id));
        if ($journal === null) {
            return ApiResponse::error(404, 'Journal not found');
        }

        return ApiResponse::success(self::journalToArray($journal));
    }

    #[Route('/api/v2/journals/{id}', name: 'api_journals_update', methods: ['PUT'])]
    public function update(int $id, Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $existing = $this->journalService->getJournalById(new EventId($id));
        if ($existing === null) {
            return ApiResponse::error(404, 'Journal not found');
        }

        $decoded = json_decode($request->getContent(), true);
        if (!\is_array($decoded)) {
            return ApiResponse::error(400, 'Invalid JSON body');
        }

        /** @var array<string, mixed> $data */
        $data = $decoded;

        $title = isset($data['title']) && \is_string($data['title']) ? $data['title'] : $existing->name();
        $text = isset($data['text']) && \is_string($data['text'])
            ? $this->descriptionSanitizer->sanitize($data['text'])
            : $existing->description();

        $updated = new Journal(
            id: $existing->id(),
            uid: $existing->uid(),
            name: $title,
            description: $text,
            location: '',
            start: $existing->start(),
            duration: 0,
            createdBy: $existing->createdBy(),
            type: $existing->type(),
            access: $existing->access(),
            allDay: true,
            sequence: $existing->sequence() + 1,
        );

        $this->journalService->updateJournal($updated, $user->getCoreUser());

        $saved = $this->journalService->getJournalById(new EventId($id));
        if ($saved === null) {
            return ApiResponse::error(500, 'Journal updated but could not be retrieved');
        }

        return ApiResponse::success(self::journalToArray($saved));
    }

    #[Route('/api/v2/journals/{id}', name: 'api_journals_delete', methods: ['DELETE'])]
    public function delete(int $id, #[CurrentUser] ?WebCalendarUser $user): Response
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $existing = $this->journalService->getJournalById(new EventId($id));
        if ($existing === null) {
            return ApiResponse::error(404, 'Journal not found');
        }

        $this->journalService->deleteJournal(new EventId($id), $user->getCoreUser());

        return ApiResponse::noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private static function journalToArray(Journal $journal): array
    {
        return [
            'id' => $journal->id()->value(),
            'uid' => $journal->uid(),
            'title' => $journal->name(),
            'text' => $journal->description(),
            'date' => $journal->start()->format('Ymd'),
            'type' => $journal->type()->value,
            'created_by' => $journal->createdBy(),
        ];
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
