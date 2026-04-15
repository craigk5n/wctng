<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use App\Service\CategoryIconRepository;
use App\Service\EmojiValidator;
use App\Service\TenantAwarePdoProvider;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use WebCalendar\Core\Application\Service\CategoryService;
use WebCalendar\Core\Domain\Entity\Category;
use WebCalendar\Core\Infrastructure\Persistence\PdoCategoryRepository;

final class CategoryController
{
    /**
     * Resolves a category by its composite primary key (cat_id, cat_owner),
     * falling back from the caller's personal row to the global row
     * (`cat_owner=''`).
     *
     * Single-id endpoints like GET/PUT/DELETE /categories/{id} accept only
     * a numeric id, but a cat_id can theoretically be shared between a
     * global and a user-owned row. Fresh installs never produce a
     * collision because core allocates ids via `MAX(cat_id)+1` across all
     * rows, but legacy-imported data can. Personal-first matches the
     * pre-4.3 behavior where the user's own category wins over a global
     * one with the same id.
     */
    private function resolveCategory(int $id, string $login): ?Category
    {
        $personal = $this->categoryRepository->findByCompositeKey($id, $login);
        if ($personal !== null) {
            return $personal;
        }
        return $this->categoryRepository->findByCompositeKey($id, '');
    }

    public function __construct(
        private readonly CategoryService $categoryService,
        private readonly PdoCategoryRepository $categoryRepository,
        private readonly CategoryIconRepository $icons,
        private readonly TenantAwarePdoProvider $pdoProvider,
        private readonly EmojiValidator $emojiValidator = new EmojiValidator(),
    ) {
        $this->icons->ensureSchema();
    }

    /**
     * @param array<string,mixed> $data
     */
    private function extractIcon(array $data): ?string
    {
        if (!\array_key_exists('icon', $data)) {
            return null;
        }
        $raw = $data['icon'];
        if ($raw === null || $raw === '') {
            return null;
        }
        return \is_string($raw) ? $raw : null;
    }

    #[Route('/api/v2/categories', name: 'api_categories_list', methods: ['GET'])]
    public function list(#[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $categories = $this->categoryService
            ->getCategoriesForUser($user->getUserIdentifier());

        $iconMap = $this->loadIconsForCategories(array_values($categories));

        $items = array_map(
            fn (Category $c) => self::categoryToArray($c, $iconMap[self::iconKey($c)] ?? null),
            $categories,
        );

        return ApiResponse::success(array_values($items));
    }

    /**
     * @param list<Category> $categories
     * @return array<string,string>
     */
    private function loadIconsForCategories(array $categories): array
    {
        $byOwner = [];
        foreach ($categories as $c) {
            $byOwner[$c->owner() ?? ''][] = $c->id();
        }
        $result = [];
        foreach ($byOwner as $owner => $ids) {
            $ownerKey = $owner === '' ? null : $owner;
            foreach ($this->icons->getBatchForOwner($ids, $ownerKey) as $id => $icon) {
                $result[$id . '|' . $owner] = $icon;
            }
        }
        return $result;
    }

    private static function iconKey(Category $c): string
    {
        return $c->id() . '|' . ($c->owner() ?? '');
    }

    #[Route('/api/v2/categories/{id}', name: 'api_categories_get', methods: ['GET'])]
    public function get(int $id, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $category = $this->resolveCategory($id, $user->getUserIdentifier());

        if ($category === null) {
            return ApiResponse::error(404, 'Category not found');
        }

        $icon = $this->icons->get($category->id(), $category->owner());
        return ApiResponse::success(self::categoryToArray($category, $icon));
    }

    #[Route('/api/v2/categories', name: 'api_categories_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
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

        $name = isset($data['name']) && \is_string($data['name']) ? $data['name'] : null;
        if ($name === null || $name === '') {
            return ApiResponse::error(400, 'Missing required field: name');
        }

        $color = isset($data['color']) && \is_string($data['color']) ? $data['color'] : null;
        $isGlobal = isset($data['is_global']) && $data['is_global'] === true;

        $icon = $this->extractIcon($data);
        if (!$this->emojiValidator->isValid($icon)) {
            return ApiResponse::error(400, 'icon must be a single emoji grapheme');
        }

        $coreUser = $user->getCoreUser();
        $owner = $isGlobal && $coreUser->isAdmin() ? null : $user->getUserIdentifier();

        $nextId = $this->categoryRepository->nextId();
        $category = new Category(
            id: $nextId,
            owner: $owner,
            name: $name,
            color: $color,
        );

        $this->categoryService->createCategory($category, $coreUser);
        $this->icons->set($nextId, $owner, $icon);

        return ApiResponse::success(self::categoryToArray($category, $icon), null, Response::HTTP_CREATED);
    }

    #[Route('/api/v2/categories/{id}', name: 'api_categories_update', methods: ['PUT'])]
    public function update(int $id, Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $existing = $this->resolveCategory($id, $user->getUserIdentifier());
        if ($existing === null) {
            return ApiResponse::error(404, 'Category not found');
        }

        $decoded = json_decode($request->getContent(), true);
        if (!\is_array($decoded)) {
            return ApiResponse::error(400, 'Invalid JSON body');
        }

        /** @var array<string, mixed> $data */
        $data = $decoded;

        $coreUser = $user->getCoreUser();
        $name = isset($data['name']) && \is_string($data['name']) ? $data['name'] : $existing->name();
        $color = isset($data['color']) && \is_string($data['color']) ? $data['color'] : $existing->color();

        $iconProvided = \array_key_exists('icon', $data);
        $icon = $iconProvided ? $this->extractIcon($data) : $this->icons->get($id, $existing->owner());
        if ($iconProvided && !$this->emojiValidator->isValid($icon)) {
            return ApiResponse::error(400, 'icon must be a single emoji grapheme');
        }

        // Handle is_global promotion/demotion (admin only)
        if (isset($data['is_global'])) {
            if (!$coreUser->isAdmin()) {
                return ApiResponse::error(403, 'Only admins can change category visibility');
            }

            $newOwner = $data['is_global'] === true ? null : $coreUser->login();

            if ($newOwner !== $existing->owner()) {
                // Owner change requires delete + re-create (core uses
                // composite key cat_id + cat_owner). Target the exact
                // existing row via deleteByCompositeKey so a sibling row
                // at the same cat_id with a different owner is never
                // collateral damage.
                $repo = $this->categoryRepository;
                $repo->deleteByCompositeKey($id, $existing->owner() ?? '');
                $this->icons->delete($id, $existing->owner());
                $promoted = new Category($id, $newOwner, $name, $color, $existing->isEnabled());
                $repo->save($promoted);
                $this->icons->set($id, $newOwner, $icon);
                return ApiResponse::success(self::categoryToArray($promoted, $icon));
            }
        }

        $updated = new Category(
            id: $id,
            owner: $existing->owner(),
            name: $name,
            color: $color,
            enabled: $existing->isEnabled(),
        );

        $this->categoryService->updateCategory($updated, $coreUser);
        if ($iconProvided) {
            $this->icons->set($id, $existing->owner(), $icon);
        }

        return ApiResponse::success(self::categoryToArray($updated, $icon));
    }

    #[Route('/api/v2/categories/{id}', name: 'api_categories_delete', methods: ['DELETE'])]
    public function delete(int $id, #[CurrentUser] ?WebCalendarUser $user): Response
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $coreUser = $user->getCoreUser();
        $existing = $this->resolveCategory($id, $user->getUserIdentifier());
        if ($existing === null) {
            return ApiResponse::error(404, 'Category not found');
        }

        // Inline the authorization rule from CategoryService::assertCanModify
        // so we can drive the delete via the composite-key method. The
        // service's single-id deleteCategory() still routes through the
        // deprecated findById/delete pair, which would wipe sibling rows
        // sharing the numeric id on legacy-imported data.
        if (!$coreUser->isAdmin()) {
            $ownerLogin = $existing->owner();
            if ($ownerLogin === null || $ownerLogin === '') {
                return ApiResponse::error(403, 'Only admins can delete a global category');
            }
            if ($ownerLogin !== $coreUser->login()) {
                return ApiResponse::error(403, 'You do not have permission to delete this category');
            }
        }

        $this->categoryRepository
            ->deleteByCompositeKey($id, $existing->owner() ?? '');
        $this->icons->delete($id, $existing->owner());

        return ApiResponse::noContent();
    }

    #[Route('/api/v2/admin/categories/merge', name: 'api_admin_categories_merge', methods: ['POST'])]
    public function merge(Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null || !$user->getCoreUser()->isAdmin()) {
            return ApiResponse::error(403, 'Admin access required');
        }

        $decoded = json_decode($request->getContent(), true);
        if (!\is_array($decoded)) {
            return ApiResponse::error(400, 'Invalid JSON body');
        }

        /** @var array{source_id?: int, target_id?: int} $data */
        $data = $decoded;
        $sourceId = $data['source_id'] ?? 0;
        $targetId = $data['target_id'] ?? 0;

        if ($sourceId <= 0 || $targetId <= 0) {
            return ApiResponse::error(400, 'Missing required fields: source_id, target_id');
        }

        if ($sourceId === $targetId) {
            return ApiResponse::error(400, 'Cannot merge a category into itself');
        }

        $repo = $this->categoryRepository;

        // Personal-first then global resolution mirrors the single-id
        // endpoints. Admin merges are typically aimed at global rows
        // promoted from legacy imports, so the global fallback is the
        // common case here.
        $adminLogin = $user->getUserIdentifier();
        $source = $this->resolveCategory($sourceId, $adminLogin);
        $target = $this->resolveCategory($targetId, $adminLogin);

        if ($source === null) {
            return ApiResponse::error(404, 'Source category not found');
        }
        if ($target === null) {
            return ApiResponse::error(404, 'Target category not found');
        }

        // Count distinct events assigned to the source category under the
        // source's owner, not across-owners. `getEventCount()` is
        // deprecated in core 4.3 because it inflates counts by joining
        // on `cat_id` alone.
        $eventCount = $repo->getEventCountByOwner($sourceId, $source->owner() ?? '');

        // Core 4.3's reassignEvents() is now per-user: it only moves
        // junction rows whose `cat_owner` matches the supplied login.
        // For an admin merge we want every user's assignments at this
        // cat_id to move, so we enumerate the distinct `cat_owner`s on
        // the source and loop. Without this, jane's assignment of the
        // soon-to-be-deleted source would be left dangling.
        $affectedOwners = $this->loadDistinctJunctionOwners($sourceId);
        foreach ($affectedOwners as $junctionOwner) {
            $repo->reassignEvents($sourceId, $targetId, $junctionOwner);
        }

        // Delete only the specific source row — not a sibling at the
        // same numeric cat_id owned by another user.
        $repo->deleteByCompositeKey($sourceId, $source->owner() ?? '');

        return ApiResponse::success([
            'merged_events' => $eventCount,
            'source' => $source->name(),
            'target' => $target->name(),
        ]);
    }

    /**
     * Returns the distinct `cat_owner` values present in the junction
     * table for a given `cat_id`. Used by the admin merge endpoint to
     * drive a per-user reassignEvents() loop because core 4.3+ scopes
     * reassignments by `cat_owner`. Lives here — not in core — because
     * it is an admin-operation concern that core's domain layer
     * intentionally does not model.
     *
     * @return list<string>
     */
    private function loadDistinctJunctionOwners(int $catId): array
    {
        $stmt = $this->pdoProvider->get()->prepare(
            'SELECT DISTINCT cat_owner FROM webcal_entry_categories WHERE cat_id = :cat_id'
        );
        $stmt->execute(['cat_id' => $catId]);
        $owners = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            if (!\is_array($row)) {
                continue;
            }
            $owner = $row['cat_owner'];
            $owners[] = \is_string($owner) ? $owner : '';
        }
        return $owners;
    }

    /**
     * @return array<string, mixed>
     */
    private static function categoryToArray(Category $category, ?string $icon = null): array
    {
        return [
            'id' => $category->id(),
            'name' => $category->name(),
            'color' => $category->color(),
            'icon' => $icon,
            'is_global' => $category->isGlobal(),
            'owner' => $category->owner(),
        ];
    }
}
