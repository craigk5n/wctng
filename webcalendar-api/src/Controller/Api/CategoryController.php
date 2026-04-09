<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use App\Service\CategoryIconRepository;
use App\Service\CoreServiceFactory;
use App\Service\EmojiValidator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use WebCalendar\Core\Domain\Entity\Category;

final class CategoryController
{
    public function __construct(
        private readonly CoreServiceFactory $coreServiceFactory,
        private readonly CategoryIconRepository $icons,
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

        $categories = $this->coreServiceFactory->getCategoryService()
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

        $category = $this->coreServiceFactory->getCategoryRepository()->findById($id);

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

        $nextId = $this->coreServiceFactory->getCategoryRepository()->nextId();
        $category = new Category(
            id: $nextId,
            owner: $owner,
            name: $name,
            color: $color,
        );

        $this->coreServiceFactory->getCategoryService()->createCategory($category, $coreUser);
        $this->icons->set($nextId, $owner, $icon);

        return ApiResponse::success(self::categoryToArray($category, $icon), null, Response::HTTP_CREATED);
    }

    #[Route('/api/v2/categories/{id}', name: 'api_categories_update', methods: ['PUT'])]
    public function update(int $id, Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $existing = $this->coreServiceFactory->getCategoryRepository()->findById($id);
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
                // Owner change requires delete + re-create (core uses composite key cat_id + cat_owner)
                $this->coreServiceFactory->getCategoryRepository()->delete($id);
                $this->icons->delete($id, $existing->owner());
                $promoted = new Category($id, $newOwner, $name, $color, $existing->isEnabled());
                $this->coreServiceFactory->getCategoryRepository()->save($promoted);
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

        $this->coreServiceFactory->getCategoryService()->updateCategory($updated, $coreUser);
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

        $existing = $this->coreServiceFactory->getCategoryRepository()->findById($id);
        if ($existing === null) {
            return ApiResponse::error(404, 'Category not found');
        }

        $coreUser = $user->getCoreUser();
        $this->coreServiceFactory->getCategoryService()->deleteCategory($id, $coreUser);
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

        $source = $this->coreServiceFactory->getCategoryRepository()->findById($sourceId);
        $target = $this->coreServiceFactory->getCategoryRepository()->findById($targetId);

        if ($source === null) {
            return ApiResponse::error(404, 'Source category not found');
        }
        if ($target === null) {
            return ApiResponse::error(404, 'Target category not found');
        }

        // Count events that will be reassigned
        $eventCount = $this->coreServiceFactory->getCategoryRepository()->getEventCount($sourceId);

        // Reassign events from source to target
        $this->coreServiceFactory->getCategoryRepository()->reassignEvents(
            $sourceId,
            $targetId,
            $user->getUserIdentifier(),
        );

        // Delete source category
        $this->coreServiceFactory->getCategoryRepository()->delete($sourceId);

        return ApiResponse::success([
            'merged_events' => $eventCount,
            'source' => $source->name(),
            'target' => $target->name(),
        ]);
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
