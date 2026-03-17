<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use App\Auth\LdapAuthenticator;
use App\Service\CoreServiceFactory;
use App\Tenant\TenantContext;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class AuthController
{
    public function __construct(
        private readonly CoreServiceFactory $coreServiceFactory,
        private readonly JWTEncoderInterface $jwtEncoder,
        private readonly int $jwtTtl,
        private readonly TenantContext $tenantContext,
        private readonly LdapAuthenticator $ldapAuthenticator,
    ) {
    }

    #[Route('/api/v2/auth/login', name: 'api_auth_login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $content = $request->getContent();
        $data = json_decode($content, true);

        if (!\is_array($data)) {
            return ApiResponse::error(400, 'Invalid JSON body');
        }

        $username = isset($data['username']) && \is_string($data['username']) ? $data['username'] : null;
        $password = isset($data['password']) && \is_string($data['password']) ? $data['password'] : null;

        if ($username === null || $username === '') {
            return ApiResponse::error(400, 'Missing required field: username');
        }

        if ($password === null || $password === '') {
            return ApiResponse::error(400, 'Missing required field: password');
        }

        // Try local password auth first
        $authService = $this->coreServiceFactory->getAuthService();
        $authenticated = $authService->authenticate($username, $password);
        $coreUser = null;

        if ($authenticated) {
            $coreUser = $this->coreServiceFactory->getUserService()->getUserByLogin($username);
        }

        // Fallback to LDAP if local auth failed
        if ($coreUser === null) {
            $coreUser = $this->ldapAuthenticator->authenticate($username, $password);
        }

        if ($coreUser === null) {
            return ApiResponse::error(401, 'Invalid credentials');
        }

        return $this->createTokenResponse($coreUser->login(), $coreUser->isAdmin(), $coreUser);
    }

    #[Route('/api/v2/auth/refresh', name: 'api_auth_refresh', methods: ['POST'])]
    public function refresh(#[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $coreUser = $user->getCoreUser();

        return $this->createTokenResponse($coreUser->login(), $coreUser->isAdmin(), $coreUser);
    }

    #[Route('/api/v2/auth/logout', name: 'api_auth_logout', methods: ['POST'])]
    public function logout(#[CurrentUser] ?WebCalendarUser $user): Response
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        // With stateless JWT, logout is handled client-side by discarding the token.
        // Server-side token blacklisting can be added later if needed.
        return ApiResponse::noContent();
    }

    private function createTokenResponse(string $login, bool $isAdmin, \WebCalendar\Core\Domain\Entity\User $coreUser): JsonResponse
    {
        $claims = [
            'username' => $login,
            'is_admin' => $isAdmin,
        ];

        // Include tenant claim in multi-tenant mode
        $tenant = $this->tenantContext->getTenant();
        if ($tenant !== null) {
            $claims['tenant'] = $tenant->slug();
        }

        $token = $this->jwtEncoder->encode($claims);

        $expiresAt = new \DateTimeImmutable('+' . $this->jwtTtl . ' seconds');

        $response = [
            'token' => $token,
            'user' => [
                'login' => $coreUser->login(),
                'firstname' => $coreUser->firstName(),
                'lastname' => $coreUser->lastName(),
                'email' => $coreUser->email(),
                'is_admin' => $isAdmin,
            ],
            'expires_at' => $expiresAt->format('c'),
        ];

        if ($tenant !== null) {
            $response['tenant'] = $tenant->slug();
        }

        return ApiResponse::success($response);
    }
}
