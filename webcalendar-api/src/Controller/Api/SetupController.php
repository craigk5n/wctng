<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Response\ApiResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use WebCalendar\Core\Application\Service\UserService;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Repository\UserRepositoryInterface;

/**
 * First-run setup endpoint for standalone installations.
 * Only accessible when no admin user exists in the database.
 */
final class SetupController
{
    public function __construct(
        private readonly UserService $userService,
        private readonly UserRepositoryInterface $userRepository,
    ) {}

    #[Route('/api/v2/setup/status', name: 'api_setup_status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        $needsSetup = !$this->adminExists();

        return ApiResponse::success([
            'needs_setup' => $needsSetup,
        ]);
    }

    #[Route('/api/v2/setup/install', name: 'api_setup_install', methods: ['POST'])]
    public function install(Request $request): JsonResponse
    {
        if ($this->adminExists()) {
            return ApiResponse::error(400, 'Setup already completed. An admin user exists.');
        }

        $decoded = json_decode($request->getContent(), true);
        if (!\is_array($decoded)) {
            return ApiResponse::error(400, 'Invalid JSON body');
        }

        /** @var array<string, mixed> $data */
        $data = $decoded;

        $username = isset($data['username']) && \is_string($data['username']) ? $data['username'] : null;
        $password = isset($data['password']) && \is_string($data['password']) ? $data['password'] : null;
        $email = isset($data['email']) && \is_string($data['email']) ? $data['email'] : null;

        if ($username === null || $username === '') {
            return ApiResponse::error(400, 'Missing required field: username');
        }
        if ($password === null || $password === '') {
            return ApiResponse::error(400, 'Missing required field: password');
        }
        if ($email === null || $email === '') {
            return ApiResponse::error(400, 'Missing required field: email');
        }

        // The repository saves by upsert, keyed on login, and this endpoint is
        // public until an administrator exists. Without this an installation
        // that has ordinary users but no administrator -- one deleted, or
        // accounts imported before setup -- would let an anonymous caller name
        // an existing login and have that account promoted to administrator,
        // its email replaced and its password overwritten. Setup creates an
        // account; it never edits one.
        if ($this->userService->getUserByLogin($username) !== null) {
            return ApiResponse::error(400, 'That username is already taken. Choose another.');
        }

        try {
            $admin = new User(
                login: $username,
                firstName: 'Admin',
                lastName: 'User',
                email: $email,
                isAdmin: true,
                isEnabled: true,
            );

            $this->userService->createUser($admin, $admin);

            $hash = $this->userService->hashPassword($password);
            $this->userRepository->setPassword($username, $hash);

            return ApiResponse::success(['message' => 'Setup complete. You can now log in.']);
        } catch (\Throwable $e) {
            return ApiResponse::error(500, 'Setup failed: ' . $e->getMessage());
        }
    }

    /**
     * Whether the installation already has an administrator.
     *
     * Any administrator, not one called "admin". Asking for that login by name
     * left setup open for good on every installation whose first account was
     * named anything else -- and /api/v2/setup/ is public, so an anonymous
     * caller could then POST themselves an administrator account on a running
     * system.
     *
     * The catch is what makes a genuinely fresh database -- one where the user
     * table does not exist yet -- report that setup is still needed.
     */
    private function adminExists(): bool
    {
        try {
            foreach ($this->userRepository->findAll() as $user) {
                if ($user->isAdmin()) {
                    return true;
                }
            }

            return false;
        } catch (\Throwable) {
            return false;
        }
    }
}
