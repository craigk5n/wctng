<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Auth\LdapAuthenticator;
use App\Response\ApiResponse;
use App\Security\PasswordUpgradeService;
use App\Security\WebCalendarUser;
use App\Tenant\TenantContext;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use WebCalendar\Core\Application\Contract\AuthServiceInterface;
use WebCalendar\Core\Application\Service\ConfigService;
use WebCalendar\Core\Application\Service\UserService;

final class AuthController
{
    public function __construct(
        private readonly AuthServiceInterface $authService,
        private readonly UserService $userService,
        private readonly ConfigService $configService,
        private readonly JWTEncoderInterface $jwtEncoder,
        private readonly int $jwtTtl,
        private readonly TenantContext $tenantContext,
        private readonly LdapAuthenticator $ldapAuthenticator,
        private readonly PasswordUpgradeService $passwordUpgradeService,
        private readonly ClockInterface $clock,
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
        $authenticated = $this->authService->authenticate($username, $password);
        $coreUser = null;

        if ($authenticated) {
            $coreUser = $this->userService->getUserByLogin($username);
            // Transparently upgrade legacy (bcrypt / non-pinned Argon2id) hashes.
            $this->passwordUpgradeService->upgradeIfNeeded($username, $password);
        }

        // Fallback to LDAP if local auth failed
        if ($coreUser === null) {
            $coreUser = $this->ldapAuthenticator->authenticate($username, $password);
        }

        if ($coreUser === null) {
            return ApiResponse::error(401, 'Invalid credentials');
        }

        $rememberMe = isset($data['remember_me']) && $data['remember_me'] === true;

        // Admin can globally disable the remember-me option; client flag is ignored when disabled.
        if ($rememberMe) {
            $disabled = $this->configService->getSetting('DISABLE_REMEMBER_ME') ?? 'N';
            if ($disabled === 'Y') {
                $rememberMe = false;
            }
        }

        return $this->createTokenResponse($coreUser->login(), $coreUser->isAdmin(), $coreUser, $rememberMe);
    }

    #[Route('/api/v2/auth/refresh', name: 'api_auth_refresh', methods: ['POST'])]
    public function refresh(Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        // Preserve remember_me flag from the current token
        $rememberMe = false;
        $authHeader = $request->headers->get('Authorization', '');
        if (str_starts_with($authHeader, 'Bearer ')) {
            $tokenStr = substr($authHeader, 7);
            $parts = explode('.', $tokenStr);
            if (\count($parts) === 3) {
                $payload = json_decode(base64_decode($parts[1]), true);
                $rememberMe = \is_array($payload) && ($payload['rem'] ?? false) === true;
            }
        }

        $coreUser = $user->getCoreUser();

        return $this->createTokenResponse($coreUser->login(), $coreUser->isAdmin(), $coreUser, $rememberMe);
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

    private function createTokenResponse(string $login, bool $isAdmin, \WebCalendar\Core\Domain\Entity\User $coreUser, bool $rememberMe = false): JsonResponse
    {
        // Resolve TTL: admin config overrides env var default
        $configKey = $rememberMe ? 'SESSION_TTL_REMEMBER_ME' : 'SESSION_TTL';
        $configDefault = $rememberMe ? '2592000' : '28800';
        $ttl = (int) ($this->configService->getSetting($configKey) ?? $configDefault);

        // Fall back to env var if config hasn't been set yet (first run)
        if ($ttl <= 0) {
            $ttl = $this->jwtTtl;
        }

        $claims = [
            'username' => $login,
            'is_admin' => $isAdmin,
            'exp' => time() + $ttl,
        ];

        // Mark remember-me tokens so refresh preserves the TTL type
        if ($rememberMe) {
            $claims['rem'] = true;
        }

        // Include tenant claim in multi-tenant mode
        $tenant = $this->tenantContext->getTenant();
        if ($tenant !== null) {
            $claims['tenant'] = $tenant->slug();
        }

        $token = $this->jwtEncoder->encode($claims);

        $expiresAt = $this->clock->now()->modify('+' . $ttl . ' seconds');

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
