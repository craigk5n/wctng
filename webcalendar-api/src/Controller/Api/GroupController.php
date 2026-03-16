<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use App\Service\CoreServiceFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use WebCalendar\Core\Domain\Entity\Group;

final class GroupController
{
    public function __construct(
        private readonly CoreServiceFactory $coreServiceFactory,
    ) {
    }

    #[Route('/api/v2/groups', name: 'api_groups_list', methods: ['GET'])]
    public function list(#[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $groups = $this->coreServiceFactory->getGroupService()->getAllGroups();
        $items = array_map(self::groupToArray(...), $groups);

        return ApiResponse::success(array_values($items));
    }

    #[Route('/api/v2/groups', name: 'api_groups_create', methods: ['POST'])]
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

        // Generate a unique ID that fits in INT column
        $id = random_int(100000, 2147483000);

        $group = new Group(
            id: $id,
            owner: $user->getUserIdentifier(),
            name: $name,
            lastUpdate: new \DateTimeImmutable(),
        );

        $this->coreServiceFactory->getGroupService()->createGroup($group);

        return ApiResponse::success(self::groupToArray($group), null, Response::HTTP_CREATED);
    }

    #[Route('/api/v2/groups/{id}', name: 'api_groups_get', methods: ['GET'])]
    public function get(int $id, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $groups = $this->coreServiceFactory->getGroupService()->getAllGroups();
        $group = null;
        foreach ($groups as $g) {
            if ($g->id() === $id) {
                $group = $g;
                break;
            }
        }

        if ($group === null) {
            return ApiResponse::error(404, 'Group not found');
        }

        $members = $this->coreServiceFactory->getGroupService()->getGroupMembers($id);

        $result = self::groupToArray($group);
        $result['members'] = $members;

        return ApiResponse::success($result);
    }

    #[Route('/api/v2/groups/{id}', name: 'api_groups_delete', methods: ['DELETE'])]
    public function delete(int $id, #[CurrentUser] ?WebCalendarUser $user): Response
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $this->coreServiceFactory->getGroupService()->deleteGroup($id);

        return ApiResponse::noContent();
    }

    #[Route('/api/v2/groups/{groupId}/members', name: 'api_groups_add_members', methods: ['POST'])]
    public function addMembers(int $groupId, Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
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

        if (!isset($data['users']) || !\is_array($data['users'])) {
            return ApiResponse::error(400, 'Missing required field: users');
        }

        /** @var list<string> $users */
        $users = $data['users'];

        foreach ($users as $login) {
            if ($login !== '') {
                $this->coreServiceFactory->getGroupService()->addMember($groupId, $login);
            }
        }

        return ApiResponse::success(['message' => 'Members added']);
    }

    #[Route('/api/v2/groups/{groupId}/members/{login}', name: 'api_groups_remove_member', methods: ['DELETE'])]
    public function removeMember(int $groupId, string $login, #[CurrentUser] ?WebCalendarUser $user): Response
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $this->coreServiceFactory->getGroupService()->removeMember($groupId, $login);

        return ApiResponse::noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private static function groupToArray(Group $group): array
    {
        return [
            'id' => $group->id(),
            'name' => $group->name(),
            'owner' => $group->owner(),
        ];
    }
}
