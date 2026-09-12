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
use WebCalendar\Core\Domain\ValueObject\EventScope;

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

        /** @var mixed $decoded */
        $decoded = json_decode($request->getContent(), true);
        /** @var array<string, mixed> $data */
        $data = \is_array($decoded) ? $decoded : [];

        $login = isset($data['login']) && \is_string($data['login']) ? trim($data['login']) : '';
        $name = isset($data['name']) && \is_string($data['name']) ? trim($data['name']) : '';

        // Trimmed before the check, because Resource trims before its own and
        // throws -- a login of spaces got past this line and came back a 500.
        if ($login === '' || $name === '') {
            return ApiResponse::error(400, 'Missing required fields: login, name');
        }

        // save() looks the login up and turns into an UPDATE when it finds
        // one, so without this the create route quietly rewrites an existing
        // resource's name, owner and visibility and answers 201.
        if ($this->resourceService->getResourceByLogin($login) !== null) {
            return ApiResponse::error(409, 'Resource with this login already exists');
        }

        $resource = new CalResource(
            login: $login,
            name: $name,
            admin: isset($data['admin']) && \is_string($data['admin']) ? $data['admin'] : $user->getUserIdentifier(),
            isPublic: isset($data['is_public']) && \is_bool($data['is_public']) ? $data['is_public'] : false,
            url: isset($data['url']) && \is_string($data['url']) ? $data['url'] : null,
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

        /** @var mixed $decoded */
        $decoded = json_decode($request->getContent(), true);
        /** @var array<string, mixed> $data */
        $data = \is_array($decoded) ? $decoded : [];

        $name = isset($data['name']) && \is_string($data['name']) ? trim($data['name']) : $existing->name();
        if ($name === '') {
            return ApiResponse::error(400, 'Resource name cannot be empty');
        }

        // array_key_exists rather than ??, so an explicit null clears the URL.
        // Under ?? a stored URL could never be removed again: the null read as
        // "not sent" and put the old value straight back.
        $url = $existing->url();
        if (\array_key_exists('url', $data)) {
            $url = \is_string($data['url']) ? $data['url'] : null;
        }

        $updated = new CalResource(
            login: $login,
            name: $name,
            admin: isset($data['admin']) && \is_string($data['admin']) ? $data['admin'] : $existing->admin(),
            isPublic: isset($data['is_public']) && \is_bool($data['is_public']) ? $data['is_public'] : $existing->isPublic(),
            url: $url,
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

        // createFromFormat rolls an impossible date forward rather than
        // refusing it -- 2026-02-31 becomes the 3rd of March -- and the reply
        // below still carries the date that was asked for, so the caller reads
        // another day's bookings under this day's label. The round trip is
        // what separates a date from a date-shaped string.
        if ($date === false || $date->format('Y-m-d') !== $dateStr) {
            return ApiResponse::error(400, 'Invalid date format');
        }

        $range = new DateRange($date->setTime(0, 0), $date->setTime(23, 59, 59));
        // Availability has to count entries it may not show, so this reads
        // the resource's calendar at every access level. Only the busy
        // start/end leaves the method; no name or description does.
        $events = $this->eventRepository->findByDateRange(
            $range,
            EventScope::administrative()->limitedToUsers([$login]),
        );

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
