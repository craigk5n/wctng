<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use App\Webhook\WebhookRepository;
use App\Webhook\WebhookSubscription;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class WebhookController
{
    public function __construct(
        private readonly WebhookRepository $repository,
    ) {}

    #[Route('/api/v2/admin/webhooks', name: 'api_webhooks_list', methods: ['GET'])]
    public function list(#[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null || !$user->getCoreUser()->isAdmin()) {
            return ApiResponse::error(403, 'Admin access required');
        }

        $webhooks = $this->repository->findAll();

        return ApiResponse::success(array_map(static fn(WebhookSubscription $w): array => $w->toArray(), $webhooks));
    }

    #[Route('/api/v2/admin/webhooks', name: 'api_webhooks_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null || !$user->getCoreUser()->isAdmin()) {
            return ApiResponse::error(403, 'Admin access required');
        }

        $decoded = json_decode($request->getContent(), true);
        if (!\is_array($decoded)) {
            return ApiResponse::error(400, 'Invalid JSON body');
        }

        /** @var array<string, mixed> $data */
        $data = $decoded;

        $url = isset($data['url']) && \is_string($data['url']) ? $data['url'] : null;
        if ($url === null || $url === '') {
            return ApiResponse::error(400, 'Missing required field: url');
        }

        $webhook = new WebhookSubscription(
            id: 0,
            url: $url,
            events: isset($data['events']) && \is_string($data['events']) ? $data['events'] : '*',
            secret: isset($data['secret']) && \is_string($data['secret']) ? $data['secret'] : bin2hex(random_bytes(16)),
            enabled: !isset($data['enabled']) || (bool) $data['enabled'],
        );

        $id = $this->repository->save($webhook);
        $saved = $this->repository->findById($id);

        return ApiResponse::success($saved?->toArray(), null, Response::HTTP_CREATED);
    }

    #[Route('/api/v2/admin/webhooks/{id}', name: 'api_webhooks_update', methods: ['PUT'])]
    public function update(int $id, Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null || !$user->getCoreUser()->isAdmin()) {
            return ApiResponse::error(403, 'Admin access required');
        }

        $existing = $this->repository->findById($id);
        if ($existing === null) {
            return ApiResponse::error(404, 'Webhook not found');
        }

        $decoded = json_decode($request->getContent(), true);
        if (!\is_array($decoded)) {
            return ApiResponse::error(400, 'Invalid JSON body');
        }

        /** @var array<string, mixed> $data */
        $data = $decoded;

        $updated = new WebhookSubscription(
            id: $id,
            url: isset($data['url']) && \is_string($data['url']) ? $data['url'] : $existing->url(),
            events: isset($data['events']) && \is_string($data['events']) ? $data['events'] : $existing->events(),
            secret: isset($data['secret']) && \is_string($data['secret']) ? $data['secret'] : $existing->secret(),
            enabled: isset($data['enabled']) ? (bool) $data['enabled'] : $existing->isEnabled(),
        );

        $this->repository->save($updated);

        return ApiResponse::success($updated->toArray());
    }

    #[Route('/api/v2/admin/webhooks/{id}', name: 'api_webhooks_delete', methods: ['DELETE'])]
    public function delete(int $id, #[CurrentUser] ?WebCalendarUser $user): Response
    {
        if ($user === null || !$user->getCoreUser()->isAdmin()) {
            return ApiResponse::error(403, 'Admin access required');
        }

        $this->repository->delete($id);

        return ApiResponse::noContent();
    }
}
