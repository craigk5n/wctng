<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use App\View\SavedViewRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class SavedViewController
{
    private readonly SavedViewRepository $repo;

    public function __construct(\PDO $pdo)
    {
        $this->repo = new SavedViewRepository($pdo);
    }

    #[Route('/api/v2/views', name: 'api_views_list', methods: ['GET'])]
    public function list(#[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) return ApiResponse::error(401, 'Authentication required');
        return ApiResponse::success($this->repo->findByOwner($user->getUserIdentifier()));
    }

    #[Route('/api/v2/views', name: 'api_views_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) return ApiResponse::error(401, 'Authentication required');

        /** @var array{name?: string, user_logins?: list<string>, is_global?: bool} $data */
        $data = json_decode((string) $request->getContent(), true) ?? [];
        $name = $data['name'] ?? '';
        if ($name === '') return ApiResponse::error(400, 'Missing required field: name');

        // Only admins can create global views
        $isGlobal = ($data['is_global'] ?? false) === true && $user->getCoreUser()->isAdmin();

        $logins = $data['user_logins'] ?? [];
        $id = $this->repo->create($user->getUserIdentifier(), $name, $logins, $isGlobal);

        return ApiResponse::success([
            'id' => $id,
            'name' => $name,
            'user_logins' => $logins,
            'is_global' => $isGlobal,
        ], null, 201);
    }

    #[Route('/api/v2/views/{id}', name: 'api_views_delete', methods: ['DELETE'])]
    public function delete(int $id, #[CurrentUser] ?WebCalendarUser $user): Response
    {
        if ($user === null) return ApiResponse::error(401, 'Authentication required');
        if (!$this->repo->delete($id, $user->getUserIdentifier())) {
            return ApiResponse::error(404, 'View not found');
        }
        return ApiResponse::noContent();
    }
}
