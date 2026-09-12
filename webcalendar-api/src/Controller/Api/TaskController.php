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
use WebCalendar\Core\Application\Service\TaskService;
use WebCalendar\Core\Domain\Entity\Task;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventScope;
use WebCalendar\Core\Domain\ValueObject\EventType;

final class TaskController
{
    public function __construct(
        private readonly TaskService $taskService,
        private readonly DescriptionSanitizer $descriptionSanitizer = new DescriptionSanitizer(),
        private readonly ClockInterface $clock = new NativeClock(),
    ) {}

    #[Route('/api/v2/tasks', name: 'api_tasks_list', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
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

        $tasks = $this->taskService->getTasksInDateRange(
            new DateRange($start, $end),
            EventScope::forUser($user->getCoreUser())->limitedToUsers([$user->getUserIdentifier()]),
        );

        $items = array_map(self::taskToArray(...), $tasks);

        return ApiResponse::success(array_values($items));
    }

    #[Route('/api/v2/tasks', name: 'api_tasks_create', methods: ['POST'])]
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

        $dueDateStr = isset($data['due_date']) && \is_string($data['due_date']) ? $data['due_date'] : null;
        $dueDate = $dueDateStr !== null ? self::parseDate($dueDateStr) : null;

        $priority = isset($data['priority']) && is_numeric($data['priority']) ? (int) $data['priority'] : 5;
        $percentComplete = isset($data['percent_complete']) && is_numeric($data['percent_complete']) ? (int) $data['percent_complete'] : 0;
        $description = isset($data['description']) && \is_string($data['description'])
            ? $this->descriptionSanitizer->sanitize($data['description'])
            : '';

        $startDate = $dueDate ?? $this->clock->now();

        $task = new Task(
            id: new EventId(0),
            uid: sprintf('wctng-task-%s@webcalendar', bin2hex(random_bytes(16))),
            name: $title,
            description: $description,
            location: '',
            start: $startDate,
            duration: 0,
            createdBy: $user->getUserIdentifier(),
            type: EventType::TASK,
            access: AccessLevel::PUBLIC,
            dueDate: $dueDate,
            percentComplete: $percentComplete,
        );

        $this->taskService->createTask($task, $user->getCoreUser());

        // Find the created task by searching recent tasks
        $range = new DateRange(
            $startDate->modify('-1 day'),
            $startDate->modify('+1 day'),
        );
        $tasks = $this->taskService->getTasksInDateRange(
            $range,
            EventScope::forUser($user->getCoreUser())->limitedToUsers([$user->getUserIdentifier()]),
        );

        // Find the one with matching UID
        $created = null;
        foreach ($tasks as $t) {
            if ($t->uid() === $task->uid()) {
                $created = $t;
                break;
            }
        }

        if ($created === null) {
            return ApiResponse::error(500, 'Task created but could not be retrieved');
        }

        return ApiResponse::success(self::taskToArray($created), null, Response::HTTP_CREATED);
    }

    #[Route('/api/v2/tasks/{id}', name: 'api_tasks_get', methods: ['GET'])]
    public function get(int $id, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $task = $this->taskService->getTaskById(new EventId($id));
        if ($task === null) {
            return ApiResponse::error(404, 'Task not found');
        }

        return ApiResponse::success(self::taskToArray($task));
    }

    #[Route('/api/v2/tasks/{id}', name: 'api_tasks_update', methods: ['PUT'])]
    public function update(int $id, Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $existing = $this->taskService->getTaskById(new EventId($id));
        if ($existing === null) {
            return ApiResponse::error(404, 'Task not found');
        }

        $decoded = json_decode($request->getContent(), true);
        if (!\is_array($decoded)) {
            return ApiResponse::error(400, 'Invalid JSON body');
        }

        /** @var array<string, mixed> $data */
        $data = $decoded;

        $title = isset($data['title']) && \is_string($data['title']) ? $data['title'] : $existing->name();
        $description = isset($data['description']) && \is_string($data['description'])
            ? $this->descriptionSanitizer->sanitize($data['description'])
            : $existing->description();
        $percentComplete = isset($data['percent_complete']) && is_numeric($data['percent_complete']) ? (int) $data['percent_complete'] : $existing->percentComplete();

        $dueDateStr = isset($data['due_date']) && \is_string($data['due_date']) ? $data['due_date'] : null;
        $dueDate = $dueDateStr !== null ? self::parseDate($dueDateStr) : $existing->dueDate();

        $updated = new Task(
            id: $existing->id(),
            uid: $existing->uid(),
            name: $title,
            description: $description,
            location: $existing->location(),
            start: $existing->start(),
            duration: $existing->duration(),
            createdBy: $existing->createdBy(),
            type: $existing->type(),
            access: $existing->access(),
            dueDate: $dueDate,
            percentComplete: $percentComplete,
            sequence: $existing->sequence() + 1,
        );

        $this->taskService->updateTask($updated, $user->getCoreUser());

        $saved = $this->taskService->getTaskById(new EventId($id));
        if ($saved === null) {
            return ApiResponse::error(500, 'Task updated but could not be retrieved');
        }

        return ApiResponse::success(self::taskToArray($saved));
    }

    #[Route('/api/v2/tasks/{id}', name: 'api_tasks_delete', methods: ['DELETE'])]
    public function delete(int $id, #[CurrentUser] ?WebCalendarUser $user): Response
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $existing = $this->taskService->getTaskById(new EventId($id));
        if ($existing === null) {
            return ApiResponse::error(404, 'Task not found');
        }

        $this->taskService->deleteTask(new EventId($id), $user->getCoreUser());

        return ApiResponse::noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private static function taskToArray(Task $task): array
    {
        return [
            'id' => $task->id()->value(),
            'uid' => $task->uid(),
            'title' => $task->name(),
            'description' => $task->description(),
            'due_date' => $task->dueDate()?->format('Ymd'),
            'due_time' => $task->dueDate()?->format('His'),
            'priority' => 5, // TODO: Task entity doesn't expose priority directly
            'percent_complete' => $task->percentComplete(),
            'type' => $task->type()->value,
            'access' => $task->access()->value,
            'created_by' => $task->createdBy(),
            'status' => $task->percentComplete() >= 100 ? 'completed' : 'pending',
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
