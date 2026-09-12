<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\CustomField\CustomFieldDefinition;
use App\CustomField\CustomFieldRepository;
use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use WebCalendar\Core\Application\Service\EventService;
use WebCalendar\Core\Application\Service\SiteExtraService;
use WebCalendar\Core\Domain\ValueObject\EventId;

final class CustomFieldController
{
    /** The width of custom_field_definitions.name, which is also UNIQUE. */
    private const MAX_NAME_LENGTH = 60;

    /** What the event form can draw. Anything else is a field nobody renders. */
    private const FIELD_TYPES = ['text', 'number', 'date', 'select', 'checkbox'];

    private const DEFAULT_FIELD_TYPE = 'text';

    public function __construct(
        private readonly SiteExtraService $siteExtraService,
        private readonly CustomFieldRepository $fieldRepo,
        private readonly EventService $eventService,
    ) {}

    public static function createForTest(
        CustomFieldRepository $fieldRepo,
        SiteExtraService $siteExtraService,
        EventService $eventService,
    ): self {
        return new self($siteExtraService, $fieldRepo, $eventService);
    }

    /** Public endpoint — returns field definitions for the event form */
    #[Route('/api/v2/custom-fields', name: 'api_custom_fields_list_public', methods: ['GET'])]
    public function listPublic(): JsonResponse
    {
        $fields = $this->fieldRepo->findAll();
        return ApiResponse::success(array_map(fn($f) => $f->toArray(), $fields));
    }

    #[Route('/api/v2/admin/custom-fields', name: 'api_admin_custom_fields_list', methods: ['GET'])]
    public function list(#[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null || !$user->getCoreUser()->isAdmin()) {
            return ApiResponse::error(403, 'Admin access required');
        }

        $fields = $this->fieldRepo->findAll();
        return ApiResponse::success(array_map(fn($f) => $f->toArray(), $fields));
    }

    #[Route('/api/v2/admin/custom-fields', name: 'api_admin_custom_fields_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null || !$user->getCoreUser()->isAdmin()) {
            return ApiResponse::error(403, 'Admin access required');
        }

        $data = self::decodeBody($request);

        $name = isset($data['name']) && \is_string($data['name']) ? trim($data['name']) : '';
        if ($name === '') {
            return ApiResponse::error(400, 'Field name is required');
        }

        if (($tooLong = self::rejectLongName($name)) !== null) {
            return $tooLong;
        }

        $fieldType = isset($data['field_type']) && \is_string($data['field_type'])
            ? $data['field_type']
            : self::DEFAULT_FIELD_TYPE;

        if (($unknownType = self::rejectUnknownType($fieldType)) !== null) {
            return $unknownType;
        }

        // The column is UNIQUE, so without this the second field of a name
        // reached the database and came back a 500 rather than saying so.
        if ($this->findByName($name) !== null) {
            return ApiResponse::error(409, 'A custom field with this name already exists');
        }

        $required = ($data['required'] ?? false) === true;
        $sortOrder = isset($data['sort_order']) && is_numeric($data['sort_order']) ? (int) $data['sort_order'] : 0;
        $options = isset($data['options']) && \is_array($data['options']) ? json_encode($data['options'], \JSON_THROW_ON_ERROR) : '';

        $field = new CustomFieldDefinition(0, $name, $fieldType, $required, $sortOrder, $options);
        $id = $this->fieldRepo->save($field);

        $saved = $this->fieldRepo->findById($id);
        return ApiResponse::success($saved?->toArray(), null, 201);
    }

    #[Route('/api/v2/admin/custom-fields/{id}', name: 'api_admin_custom_fields_update', methods: ['PUT'])]
    public function update(int $id, Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null || !$user->getCoreUser()->isAdmin()) {
            return ApiResponse::error(403, 'Admin access required');
        }

        $existing = $this->fieldRepo->findById($id);
        if ($existing === null) {
            return ApiResponse::error(404, 'Custom field not found');
        }

        $data = self::decodeBody($request);

        $name = isset($data['name']) && \is_string($data['name']) ? trim($data['name']) : $existing->name();
        if ($name === '') {
            return ApiResponse::error(400, 'Field name is required');
        }

        if (($tooLong = self::rejectLongName($name)) !== null) {
            return $tooLong;
        }

        $fieldType = isset($data['field_type']) && \is_string($data['field_type']) ? $data['field_type'] : $existing->fieldType();

        if (($unknownType = self::rejectUnknownType($fieldType)) !== null) {
            return $unknownType;
        }

        $required = isset($data['required']) ? ($data['required'] === true) : $existing->isRequired();
        $sortOrder = isset($data['sort_order']) && is_numeric($data['sort_order']) ? (int) $data['sort_order'] : $existing->sortOrder();
        $options = isset($data['options']) && \is_array($data['options']) ? json_encode($data['options'], \JSON_THROW_ON_ERROR) : $existing->options();

        $updated = new CustomFieldDefinition($id, $name, $fieldType, $required, $sortOrder, $options);
        $this->fieldRepo->save($updated);

        return ApiResponse::success($updated->toArray());
    }

    #[Route('/api/v2/admin/custom-fields/{id}', name: 'api_admin_custom_fields_delete', methods: ['DELETE'])]
    public function delete(int $id, #[CurrentUser] ?WebCalendarUser $user): Response
    {
        if ($user === null || !$user->getCoreUser()->isAdmin()) {
            return ApiResponse::error(403, 'Admin access required');
        }

        $existing = $this->fieldRepo->findById($id);
        if ($existing === null) {
            return ApiResponse::error(404, 'Custom field not found');
        }

        $this->fieldRepo->delete($id);
        return ApiResponse::noContent();
    }

    /** Save custom field values for an event */
    #[Route('/api/v2/events/{eventId}/custom-fields', name: 'api_event_custom_fields_save', methods: ['PUT'])]
    public function saveValues(int $eventId, Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        // The event id comes out of the URL and nothing checked whose event it
        // was. saveExtrasForEvent() clears every value the event already has
        // before inserting, so an empty body aimed at somebody else's id
        // erased what was there. Same rule as updating the event itself.
        $event = $this->eventService->getEventById(new EventId($eventId));
        if ($event === null) {
            return ApiResponse::error(404, 'Event not found');
        }

        $coreUser = $user->getCoreUser();
        if ($event->createdBy() !== $coreUser->login() && !$coreUser->isAdmin()) {
            return ApiResponse::error(403, 'You do not have permission to update this event');
        }

        /** @var mixed $decoded */
        $decoded = json_decode($request->getContent(), true);
        if (!\is_array($decoded)) {
            return ApiResponse::error(400, 'Invalid JSON body');
        }

        /** @var array<string, mixed> $data */
        $data = $decoded;

        $this->siteExtraService->saveExtrasForEvent($eventId, $data);

        return ApiResponse::success(['event_id' => $eventId, 'fields' => $data]);
    }

    /** Get custom field values for an event */
    #[Route('/api/v2/events/{eventId}/custom-fields', name: 'api_event_custom_fields_get', methods: ['GET'])]
    public function getValues(int $eventId, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $values = $this->siteExtraService->getExtrasForEvent($eventId);

        return ApiResponse::success($values);
    }

    /** @return array<string, mixed> */
    private static function decodeBody(Request $request): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($request->getContent(), true);

        /** @var array<string, mixed> $data */
        $data = \is_array($decoded) ? $decoded : [];

        return $data;
    }

    private static function rejectLongName(string $name): ?JsonResponse
    {
        if (mb_strlen($name) <= self::MAX_NAME_LENGTH) {
            return null;
        }

        return ApiResponse::error(
            400,
            sprintf('Field name must be %d characters or fewer.', self::MAX_NAME_LENGTH),
        );
    }

    private static function rejectUnknownType(string $fieldType): ?JsonResponse
    {
        if (\in_array($fieldType, self::FIELD_TYPES, true)) {
            return null;
        }

        return ApiResponse::error(400, 'Field type must be one of: ' . implode(', ', self::FIELD_TYPES));
    }

    private function findByName(string $name): ?CustomFieldDefinition
    {
        foreach ($this->fieldRepo->findAll() as $field) {
            if ($field->name() === $name) {
                return $field;
            }
        }

        return null;
    }
}
