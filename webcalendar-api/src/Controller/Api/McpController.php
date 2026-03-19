<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\DTO\EventResponseDTO;
use App\Service\CoreServiceFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;

/**
 * Model Context Protocol (MCP) server endpoint.
 * Enables AI assistants to read/write calendar events via JSON-RPC.
 */
final class McpController
{
    private const TOOLS = [
        'list_events' => [
            'description' => 'List calendar events in a date range',
            'parameters' => [
                'start_date' => ['type' => 'string', 'description' => 'Start date YYYYMMDD', 'required' => true],
                'end_date' => ['type' => 'string', 'description' => 'End date YYYYMMDD', 'required' => true],
            ],
        ],
        'get_event' => [
            'description' => 'Get event details by ID',
            'parameters' => [
                'id' => ['type' => 'integer', 'description' => 'Event ID', 'required' => true],
            ],
        ],
        'create_event' => [
            'description' => 'Create a new calendar event',
            'parameters' => [
                'title' => ['type' => 'string', 'description' => 'Event title', 'required' => true],
                'start_date' => ['type' => 'string', 'description' => 'Start date YYYYMMDD', 'required' => true],
                'start_time' => ['type' => 'string', 'description' => 'Start time HHMMSS (omit for all-day)', 'required' => false],
                'duration' => ['type' => 'integer', 'description' => 'Duration in minutes', 'required' => false],
                'description' => ['type' => 'string', 'description' => 'Event description', 'required' => false],
                'location' => ['type' => 'string', 'description' => 'Event location', 'required' => false],
            ],
        ],
        'update_event' => [
            'description' => 'Update an existing event',
            'parameters' => [
                'id' => ['type' => 'integer', 'description' => 'Event ID', 'required' => true],
                'title' => ['type' => 'string', 'description' => 'New title', 'required' => false],
                'start_date' => ['type' => 'string', 'description' => 'New start date YYYYMMDD', 'required' => false],
                'start_time' => ['type' => 'string', 'description' => 'New start time HHMMSS', 'required' => false],
                'duration' => ['type' => 'integer', 'description' => 'New duration in minutes', 'required' => false],
                'description' => ['type' => 'string', 'description' => 'New description', 'required' => false],
                'location' => ['type' => 'string', 'description' => 'New location', 'required' => false],
            ],
        ],
        'delete_event' => [
            'description' => 'Delete an event',
            'parameters' => [
                'id' => ['type' => 'integer', 'description' => 'Event ID', 'required' => true],
            ],
        ],
        'search_events' => [
            'description' => 'Search events by keyword',
            'parameters' => [
                'query' => ['type' => 'string', 'description' => 'Search query', 'required' => true],
            ],
        ],
        'get_availability' => [
            'description' => 'Check free/busy for a user on a date',
            'parameters' => [
                'date' => ['type' => 'string', 'description' => 'Date YYYYMMDD', 'required' => true],
                'user' => ['type' => 'string', 'description' => 'Username (defaults to token owner)', 'required' => false],
            ],
        ],
    ];

    public function __construct(
        private readonly CoreServiceFactory $factory,
    ) {
    }

    #[Route('/api/v2/mcp', name: 'api_mcp', methods: ['POST'])]
    public function handle(Request $request): JsonResponse
    {
        // Authenticate via API token
        $token = $request->headers->get('X-API-Token') ?? $request->headers->get('Authorization');
        if ($token !== null && str_starts_with($token, 'Bearer ')) {
            $token = substr($token, 7);
        }

        $user = $this->authenticateToken($token);
        if ($user === null) {
            return $this->jsonRpcError(null, -32000, 'Authentication required. Use X-API-Token header.');
        }

        /** @var array{jsonrpc?: string, method?: string, params?: array<string, mixed>, id?: string|int|null} $body */
        $body = json_decode((string) $request->getContent(), true) ?? [];

        $method = $body['method'] ?? '';
        $params = $body['params'] ?? [];
        $rpcId = $body['id'] ?? null;

        if ($method === 'tools/list') {
            return $this->jsonRpcResult($rpcId, ['tools' => $this->getToolDefinitions()]);
        }

        if ($method === 'tools/call') {
            $toolName = \is_string($params['name'] ?? null) ? $params['name'] : '';
            /** @var array<string, mixed> $toolArgs */
            $toolArgs = \is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
            return $this->callTool($rpcId, $toolName, $toolArgs, $user);
        }

        // Legacy: direct tool call by method name
        if (isset(self::TOOLS[$method])) {
            return $this->callTool($rpcId, $method, $params, $user);
        }

        return $this->jsonRpcError($rpcId, -32601, "Method not found: {$method}");
    }

    /**
     * @param array<string, mixed> $args
     */
    private function callTool(string|int|null $rpcId, string $name, array $args, User $user): JsonResponse
    {
        return match ($name) {
            'list_events' => $this->listEvents($rpcId, $args, $user),
            'get_event' => $this->getEvent($rpcId, $args),
            'create_event' => $this->createEvent($rpcId, $args, $user),
            'update_event' => $this->updateEvent($rpcId, $args, $user),
            'delete_event' => $this->deleteEvent($rpcId, $args, $user),
            'search_events' => $this->searchEvents($rpcId, $args, $user),
            'get_availability' => $this->getAvailability($rpcId, $args, $user),
            default => $this->jsonRpcError($rpcId, -32601, "Unknown tool: {$name}"),
        };
    }

    /** @param array<string, mixed> $args */
    private function listEvents(string|int|null $id, array $args, User $user): JsonResponse
    {
        $startStr = \is_string($args['start_date'] ?? null) ? $args['start_date'] : '';
        $endStr = \is_string($args['end_date'] ?? null) ? $args['end_date'] : '';

        $start = \DateTimeImmutable::createFromFormat('Ymd', $startStr);
        $end = \DateTimeImmutable::createFromFormat('Ymd', $endStr);
        if ($start === false || $end === false) {
            return $this->jsonRpcError($id, -32602, 'Invalid date. Use YYYYMMDD format.');
        }

        $range = new DateRange($start->setTime(0, 0), $end->setTime(23, 59, 59));
        $events = $this->factory->getEventService()->getEventsInDateRange($range, $user)->all();

        return $this->jsonRpcResult($id, ['events' => EventResponseDTO::fromCollection(array_values($events))]);
    }

    /** @param array<string, mixed> $args */
    private function getEvent(string|int|null $id, array $args): JsonResponse
    {
        $eventId = \is_numeric($args['id'] ?? null) ? (int) $args['id'] : 0;
        $event = $this->factory->getEventService()->getEventById(new EventId($eventId));
        if ($event === null) {
            return $this->jsonRpcError($id, -32602, 'Event not found');
        }
        return $this->jsonRpcResult($id, ['event' => EventResponseDTO::fromEntity($event)]);
    }

    /** @param array<string, mixed> $args */
    private function createEvent(string|int|null $id, array $args, User $user): JsonResponse
    {
        $title = \is_string($args['title'] ?? null) ? $args['title'] : '';
        $startDate = \is_string($args['start_date'] ?? null) ? $args['start_date'] : '';
        if ($title === '' || $startDate === '') {
            return $this->jsonRpcError($id, -32602, 'title and start_date are required');
        }

        $startTime = \is_string($args['start_time'] ?? null) ? $args['start_time'] : '';
        $allDay = $startTime === '';
        $start = $this->parseDateTime($startDate, $startTime);
        if ($start === null) {
            return $this->jsonRpcError($id, -32602, 'Invalid date/time format');
        }

        $event = new Event(
            id: new EventId(0),
            uid: sprintf('mcp-%s@webcalendar', bin2hex(random_bytes(8))),
            name: $title,
            description: \is_string($args['description'] ?? null) ? $args['description'] : '',
            location: \is_string($args['location'] ?? null) ? $args['location'] : '',
            start: $start,
            duration: \is_numeric($args['duration'] ?? null) ? (int) $args['duration'] : 60,
            createdBy: $user->login(),
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
            allDay: $allDay,
        );

        $this->factory->getEventService()->createEvent($event, $user);
        $created = $this->factory->getEventRepository()->findByUid($event->uid());

        return $this->jsonRpcResult($id, [
            'created' => true,
            'event' => $created !== null ? EventResponseDTO::fromEntity($created) : null,
        ]);
    }

    /** @param array<string, mixed> $args */
    private function updateEvent(string|int|null $id, array $args, User $user): JsonResponse
    {
        $eventId = \is_numeric($args['id'] ?? null) ? (int) $args['id'] : 0;
        $existing = $this->factory->getEventService()->getEventById(new EventId($eventId));
        if ($existing === null) {
            return $this->jsonRpcError($id, -32602, 'Event not found');
        }

        $title = \is_string($args['title'] ?? null) ? $args['title'] : $existing->name();
        $desc = \is_string($args['description'] ?? null) ? $args['description'] : $existing->description();
        $loc = \is_string($args['location'] ?? null) ? $args['location'] : $existing->location();
        $dur = \is_numeric($args['duration'] ?? null) ? (int) $args['duration'] : $existing->duration();

        $startDate = \is_string($args['start_date'] ?? null) ? $args['start_date'] : null;
        $startTime = \is_string($args['start_time'] ?? null) ? $args['start_time'] : null;
        $start = $startDate !== null ? ($this->parseDateTime($startDate, $startTime ?? '') ?? $existing->start()) : $existing->start();

        $updated = new Event(
            id: $existing->id(),
            uid: $existing->uid(),
            name: $title,
            description: $desc,
            location: $loc,
            start: $start,
            duration: $dur,
            createdBy: $existing->createdBy(),
            type: $existing->type(),
            access: $existing->access(),
            sequence: $existing->sequence() + 1,
        );

        $this->factory->getEventService()->updateEvent($updated, $user);

        return $this->jsonRpcResult($id, ['updated' => true, 'event' => EventResponseDTO::fromEntity($updated)]);
    }

    /** @param array<string, mixed> $args */
    private function deleteEvent(string|int|null $id, array $args, User $user): JsonResponse
    {
        $eventId = \is_numeric($args['id'] ?? null) ? (int) $args['id'] : 0;
        $this->factory->getEventService()->deleteEvent(new EventId($eventId), $user);
        return $this->jsonRpcResult($id, ['deleted' => true]);
    }

    /** @param array<string, mixed> $args */
    private function searchEvents(string|int|null $id, array $args, User $user): JsonResponse
    {
        $query = \is_string($args['query'] ?? null) ? $args['query'] : '';
        if ($query === '') {
            return $this->jsonRpcError($id, -32602, 'query parameter required');
        }

        $results = $this->factory->getEventRepository()->search($query, null, $user, null, 20);
        return $this->jsonRpcResult($id, ['events' => EventResponseDTO::fromCollection(array_values($results->all()))]);
    }

    /** @param array<string, mixed> $args */
    private function getAvailability(string|int|null $id, array $args, User $user): JsonResponse
    {
        $dateStr = \is_string($args['date'] ?? null) ? $args['date'] : '';
        $date = \DateTimeImmutable::createFromFormat('Ymd', $dateStr);
        if ($date === false) {
            return $this->jsonRpcError($id, -32602, 'Invalid date. Use YYYYMMDD.');
        }

        $targetLogin = \is_string($args['user'] ?? null) ? $args['user'] : $user->login();
        $targetUser = $this->factory->getUserService()->getUserByLogin($targetLogin) ?? $user;

        $slots = $this->factory->getBookingService()->getAvailability($targetUser, $date->setTime(0, 0));

        $available = array_map(fn ($slot) => [
            'start' => $slot->startDate()->format('H:i'),
            'end' => $slot->endDate()->format('H:i'),
        ], $slots);

        return $this->jsonRpcResult($id, ['date' => $dateStr, 'user' => $targetLogin, 'available_slots' => array_values($available)]);
    }

    private function authenticateToken(?string $token): ?User
    {
        if ($token === null || $token === '') {
            return null;
        }

        // Check for user with matching API token
        $users = $this->factory->getUserRepository()->findAll();
        foreach ($users as $user) {
            $prefs = $this->factory->getUserRepository()->getPreferences($user->login());
            foreach ($prefs as $pref) {
                if ($pref->key() === 'api_token' && hash_equals($pref->value(), $token)) {
                    return $user;
                }
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private function getToolDefinitions(): array
    {
        $tools = [];
        foreach (self::TOOLS as $name => $def) {
            $inputSchema = ['type' => 'object', 'properties' => [], 'required' => []];
            foreach ($def['parameters'] as $pName => $pDef) {
                $inputSchema['properties'][$pName] = [
                    'type' => $pDef['type'],
                    'description' => $pDef['description'],
                ];
                if ($pDef['required']) {
                    $inputSchema['required'][] = $pName;
                }
            }
            $tools[] = [
                'name' => $name,
                'description' => $def['description'],
                'inputSchema' => $inputSchema,
            ];
        }
        return $tools;
    }

    private function parseDateTime(string $date, string $time): ?\DateTimeImmutable
    {
        if ($time !== '') {
            $dt = \DateTimeImmutable::createFromFormat('Ymd His', $date . ' ' . $time);
        } else {
            $dt = \DateTimeImmutable::createFromFormat('Ymd', $date);
            if ($dt !== false) {
                $dt = $dt->setTime(0, 0);
            }
        }
        return $dt !== false ? $dt : null;
    }

    private function jsonRpcResult(string|int|null $id, mixed $result): JsonResponse
    {
        return new JsonResponse([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ]);
    }

    private function jsonRpcError(string|int|null $id, int $code, string $message): JsonResponse
    {
        return new JsonResponse([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ]);
    }
}
