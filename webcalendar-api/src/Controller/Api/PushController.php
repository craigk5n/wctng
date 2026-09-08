<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Push\PushSubscriptionRepository;
use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class PushController
{
    public function __construct(
        private readonly PushSubscriptionRepository $repo,
    ) {}

    #[Route('/api/v2/push/subscribe', name: 'api_push_subscribe', methods: ['POST'])]
    public function subscribe(Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        /** @var array{endpoint?: string, keys?: array{p256dh?: string, auth?: string}} $data */
        $data = json_decode($request->getContent(), true) ?? [];

        $endpoint = $data['endpoint'] ?? '';
        $p256dh = $data['keys']['p256dh'] ?? '';
        $auth = $data['keys']['auth'] ?? '';

        if ($endpoint === '' || $p256dh === '' || $auth === '') {
            return ApiResponse::error(400, 'Missing required fields: endpoint, keys.p256dh, keys.auth');
        }

        $this->repo->subscribe($user->getUserIdentifier(), $endpoint, $p256dh, $auth);

        return ApiResponse::success(['subscribed' => true], null, 201);
    }

    #[Route('/api/v2/push/unsubscribe', name: 'api_push_unsubscribe', methods: ['POST'])]
    public function unsubscribe(Request $request, #[CurrentUser] ?WebCalendarUser $user): Response
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        /** @var array{endpoint?: string} $data */
        $data = json_decode($request->getContent(), true) ?? [];
        $endpoint = $data['endpoint'] ?? '';

        if ($endpoint !== '') {
            $this->repo->unsubscribe($endpoint);
        }

        return ApiResponse::noContent();
    }

    /** Returns VAPID public key for client-side push subscription */
    #[Route('/api/v2/push/vapid-key', name: 'api_push_vapid_key', methods: ['GET'])]
    public function vapidKey(): JsonResponse
    {
        // In production, this would come from env vars
        $publicKey = $_ENV['VAPID_PUBLIC_KEY'] ?? 'BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgDzkOs-qy0Oo-3ciY_o7Z15Cqhy7Fw3aRZe0TWcuAh4I';

        return ApiResponse::success(['publicKey' => $publicKey]);
    }
}
