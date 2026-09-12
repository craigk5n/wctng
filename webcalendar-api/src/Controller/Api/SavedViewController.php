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
    /** The width of saved_views.name. */
    private const MAX_NAME_LENGTH = 100;

    public function __construct(
        private readonly SavedViewRepository $repo,
    ) {}

    #[Route('/api/v2/views', name: 'api_views_list', methods: ['GET'])]
    public function list(#[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }
        return ApiResponse::success($this->repo->findByOwner($user->getUserIdentifier()));
    }

    #[Route('/api/v2/views', name: 'api_views_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $view = self::readView($request);
        if ($view instanceof JsonResponse) {
            return $view;
        }

        // Only admins can create global views
        $isGlobal = self::wantsGlobal($request) && $user->getCoreUser()->isAdmin();

        $id = $this->repo->create(
            $user->getUserIdentifier(),
            $view['name'],
            $view['logins'],
            $isGlobal,
            $view['categories'],
        );

        return ApiResponse::success([
            'id' => $id,
            'name' => $view['name'],
            'user_logins' => $view['logins'],
            'is_global' => $isGlobal,
            'category_ids' => $view['categories'],
        ], null, 201);
    }

    #[Route('/api/v2/views/{id}', name: 'api_views_update', methods: ['PUT'])]
    public function update(int $id, Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $view = self::readView($request);
        if ($view instanceof JsonResponse) {
            return $view;
        }

        $isGlobal = self::wantsGlobal($request) && $user->getCoreUser()->isAdmin();

        $updated = $this->repo->update(
            $id,
            $user->getUserIdentifier(),
            $view['name'],
            $view['logins'],
            $isGlobal,
            $view['categories'],
        );

        if (!$updated) {
            return ApiResponse::error(404, 'View not found');
        }

        return ApiResponse::success([
            'id' => $id,
            'name' => $view['name'],
            'user_logins' => $view['logins'],
            'is_global' => $isGlobal,
            'category_ids' => $view['categories'],
        ]);
    }

    #[Route('/api/v2/views/{id}', name: 'api_views_delete', methods: ['DELETE'])]
    public function delete(int $id, #[CurrentUser] ?WebCalendarUser $user): Response
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }
        if (!$this->repo->delete($id, $user->getUserIdentifier())) {
            return ApiResponse::error(404, 'View not found');
        }
        return ApiResponse::noContent();
    }

    /**
     * The three fields a view is made of, or the response saying why it is not
     * one. Both routes take the same body and wrote it to the same columns
     * without looking: a name of the wrong type reached a typed parameter, and
     * one longer than the column reached MySQL.
     *
     * @return array{name: string, logins: list<string>, categories: list<int>}|JsonResponse
     */
    private static function readView(Request $request): array|JsonResponse
    {
        /** @var mixed $decoded */
        $decoded = json_decode($request->getContent(), true);
        /** @var array<string, mixed> $data */
        $data = \is_array($decoded) ? $decoded : [];

        $name = isset($data['name']) && \is_string($data['name']) ? trim($data['name']) : '';

        if ($name === '') {
            return ApiResponse::error(400, 'Missing required field: name');
        }

        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            return ApiResponse::error(
                400,
                sprintf('Name must be %d characters or fewer.', self::MAX_NAME_LENGTH),
            );
        }

        $logins = self::readLogins($data['user_logins'] ?? []);

        if ($logins === null) {
            return ApiResponse::error(400, 'user_logins must be a list of logins.');
        }

        $categories = self::readCategoryIds($data['category_ids'] ?? []);

        if ($categories === null) {
            return ApiResponse::error(400, 'category_ids must be a list of category ids.');
        }

        return ['name' => $name, 'logins' => $logins, 'categories' => $categories];
    }

    private static function wantsGlobal(Request $request): bool
    {
        /** @var mixed $decoded */
        $decoded = json_decode($request->getContent(), true);

        return \is_array($decoded) && ($decoded['is_global'] ?? false) === true;
    }

    /**
     * Logins are filtered to strings again on the way out, so anything else
     * stored here came back missing -- a write answering with a list the next
     * read would not return.
     *
     * @return list<string>|null null when it is not a list of logins at all
     */
    private static function readLogins(mixed $value): ?array
    {
        if (!\is_array($value)) {
            return null;
        }

        $logins = [];

        /** @var mixed $login */
        foreach ($value as $login) {
            if (!\is_string($login)) {
                return null;
            }

            $logins[] = $login;
        }

        return $logins;
    }

    /**
     * The read side takes anything numeric and casts it, so this does too --
     * otherwise a "3" written comes back as 3 and the two disagree.
     *
     * @return list<int>|null null when it is not a list of category ids at all
     */
    private static function readCategoryIds(mixed $value): ?array
    {
        if (!\is_array($value)) {
            return null;
        }

        $ids = [];

        /** @var mixed $id */
        foreach ($value as $id) {
            if (!\is_int($id) && !(\is_string($id) && ctype_digit($id))) {
                return null;
            }

            $ids[] = (int) $id;
        }

        return $ids;
    }
}
