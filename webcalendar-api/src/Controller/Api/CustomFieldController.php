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
use WebCalendar\Core\Application\Service\SiteExtraService;

final class CustomFieldController
{
    private readonly CustomFieldRepository $fieldRepo;

    public function __construct(
        private readonly SiteExtraService $siteExtraService,
        \PDO $pdo,
    ) {
        $this->fieldRepo = new CustomFieldRepository($pdo);
    }

    public static function createForTest(CustomFieldRepository $fieldRepo, SiteExtraService $siteExtraService): self
    {
        $instance = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $ref = new \ReflectionProperty(self::class, 'fieldRepo');
        $ref->setValue($instance, $fieldRepo);
        $ref = new \ReflectionProperty(self::class, 'siteExtraService');
        $ref->setValue($instance, $siteExtraService);
        return $instance;
    }

    /** Public endpoint — returns field definitions for the event form */
    #[Route('/api/v2/custom-fields', name: 'api_custom_fields_list_public', methods: ['GET'])]
    public function listPublic(): JsonResponse
    {
        $fields = $this->fieldRepo->findAll();
        return ApiResponse::success(array_map(fn ($f) => $f->toArray(), $fields));
    }

    #[Route('/api/v2/admin/custom-fields', name: 'api_admin_custom_fields_list', methods: ['GET'])]
    public function list(#[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null || !$user->getCoreUser()->isAdmin()) {
            return ApiResponse::error(403, 'Admin access required');
        }

        $fields = $this->fieldRepo->findAll();
        return ApiResponse::success(array_map(fn ($f) => $f->toArray(), $fields));
    }

    #[Route('/api/v2/admin/custom-fields', name: 'api_admin_custom_fields_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null || !$user->getCoreUser()->isAdmin()) {
            return ApiResponse::error(403, 'Admin access required');
        }

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $request->getContent(), true) ?? [];

        $name = isset($data['name']) && \is_string($data['name']) ? trim($data['name']) : '';
        if ($name === '') {
            return ApiResponse::error(400, 'Field name is required');
        }

        $fieldType = isset($data['field_type']) && \is_string($data['field_type']) ? $data['field_type'] : 'text';
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

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $request->getContent(), true) ?? [];

        $name = isset($data['name']) && \is_string($data['name']) ? trim($data['name']) : $existing->name();
        $fieldType = isset($data['field_type']) && \is_string($data['field_type']) ? $data['field_type'] : $existing->fieldType();
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

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $request->getContent(), true) ?? [];

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
}
