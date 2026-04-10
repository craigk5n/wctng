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
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Exception\SelfOperationException;

final class UserController
{
    public function __construct(
        private readonly CoreServiceFactory $coreServiceFactory,
    ) {
    }

    #[Route('/api/v2/users', name: 'api_users_list', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $coreUser = $user->getCoreUser();
        $userService = $this->coreServiceFactory->getUserService();

        $enabledFilter = $request->query->get('enabled');

        if ($enabledFilter === 'true') {
            // getAllUsers throws AuthorizationException if not admin
            $users = $userService->getAllUsers($coreUser);
            $users = array_filter($users, static fn (User $u) => $u->isEnabled());
        } elseif ($enabledFilter === 'false') {
            $users = $userService->getAllUsers($coreUser);
            $users = array_filter($users, static fn (User $u) => !$u->isEnabled());
        } else {
            $users = $userService->getAllUsers($coreUser);
        }

        $items = array_map(self::userToArray(...), $users);

        return ApiResponse::paginated(array_values($items), \count($items), 1, \count($items));
    }

    #[Route('/api/v2/users/{login}', name: 'api_users_get', methods: ['GET'])]
    public function get(string $login, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $coreUser = $user->getCoreUser();

        // Non-admin can only view self
        if (!$coreUser->isAdmin() && $coreUser->login() !== $login) {
            return ApiResponse::error(403, 'You do not have permission to view this user');
        }

        $targetUser = $this->coreServiceFactory->getUserService()->getUserByLogin($login);

        if ($targetUser === null) {
            return ApiResponse::error(404, 'User not found');
        }

        return ApiResponse::success(self::userToArray($targetUser));
    }

    #[Route('/api/v2/users', name: 'api_users_create', methods: ['POST'])]
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

        $login = isset($data['login']) && \is_string($data['login']) ? $data['login'] : null;
        $password = isset($data['password']) && \is_string($data['password']) ? $data['password'] : null;
        $email = isset($data['email']) && \is_string($data['email']) ? $data['email'] : null;

        if ($login === null || $login === '') {
            return ApiResponse::error(400, 'Missing required field: login');
        }

        if ($password === null || $password === '') {
            return ApiResponse::error(400, 'Missing required field: password');
        }

        if ($email === null || $email === '') {
            return ApiResponse::error(400, 'Missing required field: email');
        }

        // Check if login already exists
        $existing = $this->coreServiceFactory->getUserService()->getUserByLogin($login);
        if ($existing !== null) {
            return ApiResponse::error(409, 'User with this login already exists');
        }

        $firstname = isset($data['firstname']) && \is_string($data['firstname']) ? $data['firstname'] : '';
        $lastname = isset($data['lastname']) && \is_string($data['lastname']) ? $data['lastname'] : '';
        $isAdmin = isset($data['is_admin']) && $data['is_admin'] === true;

        $newUser = new User(
            login: $login,
            firstName: $firstname,
            lastName: $lastname,
            email: $email,
            isAdmin: $isAdmin,
            isEnabled: true,
        );

        $coreUser = $user->getCoreUser();

        // createUser throws AuthorizationException if not admin
        $this->coreServiceFactory->getUserService()->createUser($newUser, $coreUser);

        // Set password
        $hash = $this->coreServiceFactory->getUserService()->hashPassword($password);
        $this->coreServiceFactory->getUserRepository()->setPassword($login, $hash);

        return ApiResponse::success(self::userToArray($newUser), null, Response::HTTP_CREATED);
    }

    #[Route('/api/v2/users/{login}/password', name: 'api_users_change_password', methods: ['PUT'])]
    public function changePassword(string $login, Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $coreUser = $user->getCoreUser();

        // Non-admin can only change own password
        if (!$coreUser->isAdmin() && $coreUser->login() !== $login) {
            return ApiResponse::error(403, 'You do not have permission to change this password');
        }

        $targetUser = $this->coreServiceFactory->getUserService()->getUserByLogin($login);
        if ($targetUser === null) {
            return ApiResponse::error(404, 'User not found');
        }

        $decoded = json_decode($request->getContent(), true);
        if (!\is_array($decoded)) {
            return ApiResponse::error(400, 'Invalid JSON body');
        }

        /** @var array<string, mixed> $data */
        $data = $decoded;

        $newPassword = isset($data['new_password']) && \is_string($data['new_password']) ? $data['new_password'] : null;
        if ($newPassword === null || $newPassword === '') {
            return ApiResponse::error(400, 'Missing required field: new_password');
        }

        // Non-admin must provide current_password
        if (!$coreUser->isAdmin()) {
            $currentPassword = isset($data['current_password']) && \is_string($data['current_password']) ? $data['current_password'] : null;
            if ($currentPassword === null || $currentPassword === '') {
                return ApiResponse::error(400, 'Missing required field: current_password');
            }

            $authService = $this->coreServiceFactory->getAuthService();
            if (!$authService->authenticate($login, $currentPassword)) {
                return ApiResponse::error(400, 'Current password is incorrect');
            }
        }

        $hash = $this->coreServiceFactory->getUserService()->hashPassword($newPassword);
        $this->coreServiceFactory->getUserRepository()->setPassword($login, $hash);

        return ApiResponse::success(['message' => 'Password changed successfully']);
    }

    #[Route('/api/v2/users/{login}', name: 'api_users_update', methods: ['PUT'])]
    public function update(string $login, Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $coreUser = $user->getCoreUser();

        $targetUser = $this->coreServiceFactory->getUserService()->getUserByLogin($login);
        if ($targetUser === null) {
            return ApiResponse::error(404, 'User not found');
        }

        $decoded = json_decode($request->getContent(), true);
        if (!\is_array($decoded)) {
            return ApiResponse::error(400, 'Invalid JSON body');
        }

        /** @var array<string, mixed> $data */
        $data = $decoded;

        $firstname = isset($data['firstname']) && \is_string($data['firstname']) ? $data['firstname'] : $targetUser->firstName();
        $lastname = isset($data['lastname']) && \is_string($data['lastname']) ? $data['lastname'] : $targetUser->lastName();
        $email = isset($data['email']) && \is_string($data['email']) ? $data['email'] : $targetUser->email();

        // Admin-only fields
        $isAdmin = $targetUser->isAdmin();
        $isEnabled = $targetUser->isEnabled();
        if ($coreUser->isAdmin()) {
            if (isset($data['is_admin']) && \is_bool($data['is_admin'])) {
                $isAdmin = $data['is_admin'];
            }
            if (isset($data['enabled']) && \is_bool($data['enabled'])) {
                $isEnabled = $data['enabled'];
            }
        }

        $updatedUser = new User(
            login: $login,
            firstName: $firstname,
            lastName: $lastname,
            email: $email,
            isAdmin: $isAdmin,
            isEnabled: $isEnabled,
        );

        try {
            // updateUser throws AuthorizationException if not admin or self
            $this->coreServiceFactory->getUserService()->updateUser($updatedUser, $coreUser);
        } catch (SelfOperationException $e) {
            return ApiResponse::error(409, $e->getMessage());
        }

        return ApiResponse::success(self::userToArray($updatedUser));
    }

    #[Route('/api/v2/users/{login}', name: 'api_users_delete', methods: ['DELETE'])]
    public function delete(string $login, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $coreUser = $user->getCoreUser();
        $userService = $this->coreServiceFactory->getUserService();

        $targetUser = $userService->getUserByLogin($login);
        if ($targetUser === null) {
            return ApiResponse::error(404, 'User not found');
        }

        try {
            $userService->deleteUser($login, $coreUser);
        } catch (SelfOperationException $e) {
            return ApiResponse::error(409, $e->getMessage());
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/v2/users/{login}/preferences', name: 'api_users_get_preferences', methods: ['GET'])]
    public function getPreferences(string $login, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $coreUser = $user->getCoreUser();
        $prefs = $this->coreServiceFactory->getUserService()->getPreferences($login, $coreUser);

        $items = [];
        foreach ($prefs as $pref) {
            $items[] = ['key' => $pref->key(), 'value' => $pref->value()];
        }

        return ApiResponse::success($items);
    }

    #[Route('/api/v2/users/{login}/preferences', name: 'api_users_set_preferences', methods: ['PUT'])]
    public function setPreferences(string $login, Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $decoded = json_decode($request->getContent(), true);
        if (!\is_array($decoded)) {
            return ApiResponse::error(400, 'Invalid JSON body');
        }

        $coreUser = $user->getCoreUser();

        /** @var array<string, string> $prefMap */
        $prefMap = [];
        /**
         * @var string $k
         * @var mixed $v
         */
        foreach ($decoded as $k => $v) {
            if (\is_string($v)) {
                $prefMap[$k] = $v;
            }
        }

        foreach ($prefMap as $key => $value) {
            $this->coreServiceFactory->getUserService()->updatePreference($login, $key, $value, $coreUser);
        }

        return ApiResponse::success(['message' => 'Preferences saved']);
    }

    #[Route('/api/v2/users/{login}/location', name: 'api_users_get_location', methods: ['GET'])]
    public function getLocation(string $login, Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $date = $request->query->getString('date', date('Y-m-d'));
        $prefKey = "working_location_{$date}";

        $prefs = $this->coreServiceFactory->getUserRepository()->getPreferences($login);
        $location = 'office'; // default
        foreach ($prefs as $pref) {
            if ($pref->key() === $prefKey) {
                $location = $pref->value();
                break;
            }
        }

        return ApiResponse::success(['location' => $location, 'date' => $date]);
    }

    #[Route('/api/v2/users/{login}/location', name: 'api_users_set_location', methods: ['PUT'])]
    public function setLocation(string $login, Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        // Only own location
        if ($user->getUserIdentifier() !== $login && !$user->getCoreUser()->isAdmin()) {
            return ApiResponse::error(403, 'Cannot set another user\'s location');
        }

        $decoded = json_decode($request->getContent(), true);
        if (!\is_array($decoded)) {
            return ApiResponse::error(400, 'Invalid JSON body');
        }

        /** @var array{date?: string, location?: string} $data */
        $data = $decoded;
        $date = $data['date'] ?? date('Y-m-d');
        $location = $data['location'] ?? 'office';

        $prefKey = "working_location_{$date}";
        $this->coreServiceFactory->getUserRepository()->savePreference(
            $login,
            new \WebCalendar\Core\Domain\ValueObject\UserPreference($prefKey, $location),
        );

        return ApiResponse::success(['location' => $location, 'date' => $date]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function userToArray(User $user): array
    {
        return [
            'login' => $user->login(),
            'firstname' => $user->firstName(),
            'lastname' => $user->lastName(),
            'email' => $user->email(),
            'is_admin' => $user->isAdmin(),
            'enabled' => $user->isEnabled(),
        ];
    }
}
