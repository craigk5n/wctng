<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class AccessController
{
    public function __construct(
        private readonly \PDO $pdo,
    ) {
    }

    /**
     * List user-to-user access permissions for the current user's calendar.
     */
    #[Route('/api/v2/access/users', name: 'api_access_list', methods: ['GET'])]
    public function list(#[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $stmt = $this->pdo->prepare(
            'SELECT cal_other_user, cal_can_view, cal_can_edit, cal_see_time_only
             FROM webcal_access_user WHERE cal_login = :login',
        );
        $stmt->execute(['login' => $user->getUserIdentifier()]);

        $items = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            if (\is_array($row)) {
                /** @var int|string $canView */
                $canView = $row['cal_can_view'] ?? 0;
                /** @var int|string $canEdit */
                $canEdit = $row['cal_can_edit'] ?? 0;
                $items[] = [
                    'login' => $row['cal_other_user'],
                    'can_view' => (int) $canView > 0,
                    'can_edit' => (int) $canEdit > 0,
                    'see_time_only' => ($row['cal_see_time_only'] ?? 'N') === 'Y',
                ];
            }
        }

        return ApiResponse::success($items);
    }

    /**
     * Set permissions for a specific user on the current user's calendar.
     */
    #[Route('/api/v2/access/users/{login}', name: 'api_access_set', methods: ['PUT'])]
    public function set(string $login, Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
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

        $canView = isset($data['can_view']) && $data['can_view'] ? 1 : 0;
        $canEdit = isset($data['can_edit']) && $data['can_edit'] ? 1 : 0;
        $seeTimeOnly = isset($data['see_time_only']) && $data['see_time_only'] ? 'Y' : 'N';

        // Upsert
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM webcal_access_user WHERE cal_login = :owner AND cal_other_user = :other',
        );
        $stmt->execute(['owner' => $user->getUserIdentifier(), 'other' => $login]);

        if ($stmt->fetch()) {
            $sql = 'UPDATE webcal_access_user SET cal_can_view = :view, cal_can_edit = :edit, cal_see_time_only = :time_only
                    WHERE cal_login = :owner AND cal_other_user = :other';
        } else {
            $sql = 'INSERT INTO webcal_access_user (cal_login, cal_other_user, cal_can_view, cal_can_edit, cal_see_time_only)
                    VALUES (:owner, :other, :view, :edit, :time_only)';
        }

        $this->pdo->prepare($sql)->execute([
            'owner' => $user->getUserIdentifier(),
            'other' => $login,
            'view' => $canView,
            'edit' => $canEdit,
            'time_only' => $seeTimeOnly,
        ]);

        return ApiResponse::success([
            'login' => $login,
            'can_view' => $canView > 0,
            'can_edit' => $canEdit > 0,
            'see_time_only' => $seeTimeOnly === 'Y',
        ]);
    }
}
