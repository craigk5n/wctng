<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use App\Service\PurgeService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Admin-only event management endpoints (bulk purge).
 *
 * See STATUS.md DEL-S1 for the story and acceptance criteria.
 */
final class AdminEventController
{
    public function __construct(
        private readonly PurgeService $purgeService,
    ) {}

    #[Route('/api/v2/admin/events/purge', name: 'api_admin_events_purge', methods: ['POST'])]
    public function purge(Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null || !$user->getCoreUser()->isAdmin()) {
            return ApiResponse::error(403, 'Admin access required');
        }

        /** @var array<string,mixed> $data */
        $data = json_decode($request->getContent(), true) ?? [];

        $beforeDateRaw = isset($data['before_date']) && \is_string($data['before_date'])
            ? $data['before_date']
            : null;
        if ($beforeDateRaw === null || $beforeDateRaw === '') {
            return ApiResponse::error(400, 'before_date is required (YYYY-MM-DD)');
        }

        try {
            $beforeDate = new \DateTimeImmutable($beforeDateRaw);
        } catch (\Exception) {
            return ApiResponse::error(400, 'before_date must be a valid ISO date (YYYY-MM-DD)');
        }

        $userLogin = isset($data['user_login']) && \is_string($data['user_login']) && $data['user_login'] !== ''
            ? $data['user_login']
            : null;
        $includeRepeating = (bool) ($data['include_repeating'] ?? false);
        $dryRun = \array_key_exists('dry_run', $data) ? (bool) $data['dry_run'] : true;
        $confirmCount = isset($data['confirm_count']) && \is_int($data['confirm_count'])
            ? $data['confirm_count']
            : null;

        try {
            $result = $this->purgeService->purge(
                beforeDate: $beforeDate,
                userLogin: $userLogin,
                includeRepeating: $includeRepeating,
                dryRun: $dryRun,
                confirmCount: $confirmCount,
                actor: $user->getCoreUser()->login(),
            );
        } catch (\DomainException $e) {
            return ApiResponse::error(422, $e->getMessage());
        } catch (\Throwable $e) {
            return ApiResponse::error(500, 'Purge failed: ' . $e->getMessage());
        }

        return ApiResponse::success($result->toArray());
    }
}
