<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use WebCalendar\Core\Application\Service\ResourceService;
use WebCalendar\Core\Domain\Entity\Resource as CalResource;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\DateRange;

final class ResourceController
{
    public function __construct(
        private readonly ResourceService $resourceService,
        private readonly EventRepositoryInterface $eventRepository,
    ) {}

    #[Route('/api/v2/admin/resources', name: 'api_admin_resources_list', methods: ['GET'])]
    public function list(#[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null || !$user->getCoreUser()->isAdmin()) {
            return ApiResponse::error(403, 'Admin access required');
        }

        $resources = $this->resourceService->getAllResources();
        $items = array_map([$this, 'formatResource'], $resources);
        return ApiResponse::success(array_values($items));
    }

    #[Route('/api/v2/admin/resources', name: 'api_admin_resources_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null || !$user->getCoreUser()->isAdmin()) {
            return ApiResponse::error(403, 'Admin access required');
        }

        /** @var array{login?: string, name?: string, admin?: string, is_public?: bool, url?: string} $data */
        $data = json_decode((string) $request->getContent(), true) ?? [];

        $login = $data['login'] ?? '';
        $name = $data['name'] ?? '';
        if ($login === '' || $name === '') {
            return ApiResponse::error(400, 'Missing required fields: login, name');
        }

        $resource = new CalResource(
            login: $login,
            name: $name,
            admin: $data['admin'] ?? $user->getUserIdentifier(),
            isPublic: $data['is_public'] ?? false,
            url: $data['url'] ?? null,
        );

        $this->resourceService->createResource($resource);
        return ApiResponse::success($this->formatResource($resource), null, 201);
    }

    #[Route('/api/v2/admin/resources/{login}', name: 'api_admin_resources_update', methods: ['PUT'])]
    public function update(string $login, Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null || !$user->getCoreUser()->isAdmin()) {
            return ApiResponse::error(403, 'Admin access required');
        }

        $existing = $this->resourceService->getResourceByLogin($login);
        if ($existing === null) {
            return ApiResponse::error(404, 'Resource not found');
        }

        /** @var array{name?: string, admin?: string, is_public?: bool, url?: string} $data */
        $data = json_decode((string) $request->getContent(), true) ?? [];

        $updated = new CalResource(
            login: $login,
            name: $data['name'] ?? $existing->name(),
            admin: $data['admin'] ?? $existing->admin(),
            isPublic: $data['is_public'] ?? $existing->isPublic(),
            url: $data['url'] ?? $existing->url(),
        );

        $this->resourceService->updateResource($updated);
        return ApiResponse::success($this->formatResource($updated));
    }

    #[Route('/api/v2/admin/resources/{login}', name: 'api_admin_resources_delete', methods: ['DELETE'])]
    public function delete(string $login, #[CurrentUser] ?WebCalendarUser $user): Response
    {
        if ($user === null || !$user->getCoreUser()->isAdmin()) {
            return ApiResponse::error(403, 'Admin access required');
        }

        $this->resourceService->deleteResource($login);
        return ApiResponse::noContent();
    }

    #[Route('/api/v2/resources/{login}/availability', name: 'api_resources_availability', methods: ['GET'])]
    public function availability(string $login, Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $dateStr = $request->query->getString('date', '');
        if ($dateStr === '') {
            return ApiResponse::error(400, 'Missing required query param: date (YYYY-MM-DD)');
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $dateStr);
        if ($date === false) {
            return ApiResponse::error(400, 'Invalid date format');
        }

        $range = new DateRange($date->setTime(0, 0), $date->setTime(23, 59, 59));
        $events = $this->eventRepository->findByDateRange($range, null, null, [$login]);

        $busy = array_map(static fn($e) => [
            'title' => $e->name(),
            'start' => $e->start()->format('H:i'),
            'end' => $e->end()->format('H:i'),
        ], $events);

        return ApiResponse::success([
            'resource' => $login,
            'date' => $dateStr,
            'busy' => array_values($busy),
        ]);
    }

    /** @return array<string, mixed> */
    private function formatResource(CalResource $r): array
    {
        return [
            'login' => $r->login(),
            'name' => $r->name(),
            'admin' => $r->admin(),
            'is_public' => $r->isPublic(),
            'url' => $r->url(),
        ];
    }
}
