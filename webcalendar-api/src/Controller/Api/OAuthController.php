<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Auth\OAuthProviderRepository;
use App\Response\ApiResponse;
use App\Security\OutboundUrlValidator;
use App\Security\UserTokenIndex;
use App\Tenant\TenantContext;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use WebCalendar\Core\Application\Service\UserService;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Repository\UserRepositoryInterface;

/**
 * OAuth2 authorization code flow with PKCE support.
 */
final class OAuthController
{
    public function __construct(
        private readonly OAuthProviderRepository $providerRepo,
        private readonly UserService $userService,
        private readonly UserRepositoryInterface $userRepository,
        private readonly JWTEncoderInterface $jwtEncoder,
        private readonly TenantContext $tenantContext,
        private readonly int $jwtTtl,
        private readonly ClockInterface $clock,
        private readonly UserTokenIndex $tokenIndex,
        private readonly OutboundUrlValidator $urlValidator,
    ) {}

    /**
     * Redirects to the OAuth provider's authorization URL.
     */
    #[Route('/api/v2/auth/oauth/{providerId}/redirect', name: 'api_oauth_redirect', methods: ['GET'])]
    public function redirect(int $providerId, Request $request): Response
    {
        $provider = $this->providerRepo->findById($providerId);
        if ($provider === null || !$provider->isEnabled()) {
            return ApiResponse::error(404, 'OAuth provider not found or disabled');
        }

        // Generate PKCE code verifier and challenge
        $codeVerifier = bin2hex(random_bytes(32));
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        // Generate state token
        $state = bin2hex(random_bytes(16));

        // Store in session (via query params for stateless — client must store these)
        $params = http_build_query([
            'response_type' => 'code',
            'client_id' => $provider->clientId(),
            'redirect_uri' => $this->getCallbackUrl($request, $providerId),
            'scope' => $provider->scopes(),
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);

        $authUrl = $provider->authUrl() . '?' . $params;

        // Return the redirect URL and PKCE verifier for the client to store
        // For browser-based flow, client stores code_verifier in sessionStorage
        return ApiResponse::success([
            'auth_url' => $authUrl,
            'state' => $state,
            'code_verifier' => $codeVerifier,
        ]);
    }

    /**
     * Handles the OAuth callback, exchanges code for token, provisions user, issues JWT.
     */
    #[Route('/api/v2/auth/oauth/{providerId}/callback', name: 'api_oauth_callback', methods: ['POST'])]
    public function callback(int $providerId, Request $request): JsonResponse
    {
        $provider = $this->providerRepo->findById($providerId);
        if ($provider === null || !$provider->isEnabled()) {
            return ApiResponse::error(404, 'OAuth provider not found or disabled');
        }

        $decoded = json_decode($request->getContent(), true);
        if (!\is_array($decoded)) {
            return ApiResponse::error(400, 'Invalid JSON body');
        }

        /** @var array<string, mixed> $data */
        $data = $decoded;

        $code = isset($data['code']) && \is_string($data['code']) ? $data['code'] : null;
        $codeVerifier = isset($data['code_verifier']) && \is_string($data['code_verifier']) ? $data['code_verifier'] : null;

        if ($code === null || $code === '') {
            return ApiResponse::error(400, 'Missing required field: code');
        }

        // Exchange code for access token
        $tokenResponse = $this->exchangeCode($provider->tokenUrl(), [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $provider->clientId(),
            'client_secret' => $provider->clientSecret(),
            'redirect_uri' => $this->getCallbackUrl($request, $providerId),
            'code_verifier' => $codeVerifier ?? '',
        ]);

        if ($tokenResponse === null) {
            return ApiResponse::error(401, 'Failed to exchange authorization code');
        }

        $accessToken = $tokenResponse['access_token'] ?? null;
        if (!\is_string($accessToken)) {
            return ApiResponse::error(401, 'Invalid token response from provider');
        }

        // Fetch user profile
        $profile = $this->fetchUserProfile($provider->userinfoUrl(), $accessToken);
        if ($profile === null) {
            return ApiResponse::error(401, 'Failed to fetch user profile from provider');
        }

        // Auto-provision or find user
        $email = \is_string($profile['email'] ?? null) ? $profile['email'] : '';
        $name = \is_string($profile['name'] ?? null) ? $profile['name'] : '';
        $sub = \is_string($profile['sub'] ?? null) ? $profile['sub'] : $email;

        if ($email === '' && $sub === '') {
            return ApiResponse::error(401, 'OAuth profile missing email or subject');
        }

        $login = $this->normalizeLogin($email !== '' ? $email : $sub);
        $user = $this->provisionOrFindUser($login, $name, $email);

        if ($user === null) {
            return ApiResponse::error(500, 'Failed to provision user');
        }

        // Issue JWT (with jti + exp so PBP-S6 revocation can target it)
        $issuedAt = $this->clock->now();
        $expires = $issuedAt->getTimestamp() + $this->jwtTtl;
        $jti = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');

        $claims = [
            'jti' => $jti,
            'typ' => 'access',
            'username' => $user->login(),
            'is_admin' => $user->isAdmin(),
            'iat' => $issuedAt->getTimestamp(),
            'exp' => $expires,
        ];

        $tenant = $this->tenantContext->getTenant();
        if ($tenant !== null) {
            $claims['tenant'] = $tenant->slug();
        }

        $jwt = $this->jwtEncoder->encode($claims);
        $this->tokenIndex->record($user->login(), $jti, $expires);
        $expiresAt = $issuedAt->modify('+' . $this->jwtTtl . ' seconds');

        $response = [
            'token' => $jwt,
            'user' => [
                'login' => $user->login(),
                'firstname' => $user->firstName(),
                'lastname' => $user->lastName(),
                'email' => $user->email(),
                'is_admin' => $user->isAdmin(),
            ],
            'expires_at' => $expiresAt->format('c'),
        ];

        if ($tenant !== null) {
            $response['tenant'] = $tenant->slug();
        }

        return ApiResponse::success($response);
    }

    /**
     * @param array<string, string> $params
     *
     * @return array<string, mixed>|null
     */
    private function exchangeCode(string $tokenUrl, array $params): ?array
    {
        if ($tokenUrl === '') {
            return null;
        }

        // $params carries the client secret, so an attacker-controlled token_url
        // would not merely reach inside the network, it would be handed the
        // provider credentials.
        try {
            $target = $this->urlValidator->validate($tokenUrl);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $ch = curl_init($tokenUrl);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_TIMEOUT => 10,
        ] + OutboundUrlValidator::curlSecurityOptions($target));

        $response = curl_exec($ch);
        curl_close($ch);

        if (!\is_string($response)) {
            return null;
        }

        $decoded = json_decode($response, true);

        /** @var array<string, mixed>|null */
        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchUserProfile(string $userinfoUrl, #[\SensitiveParameter] string $accessToken): ?array
    {
        if ($userinfoUrl === '') {
            return null;
        }

        // The access token rides in the Authorization header, so the same
        // reasoning as exchangeCode() applies.
        try {
            $target = $this->urlValidator->validate($userinfoUrl);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $ch = curl_init($userinfoUrl);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$accessToken}", 'Accept: application/json'],
            CURLOPT_TIMEOUT => 10,
        ] + OutboundUrlValidator::curlSecurityOptions($target));

        $response = curl_exec($ch);
        curl_close($ch);

        if (!\is_string($response)) {
            return null;
        }

        $decoded = json_decode($response, true);

        /** @var array<string, mixed>|null */
        return \is_array($decoded) ? $decoded : null;
    }

    private function provisionOrFindUser(string $login, string $name, string $email): ?User
    {
        $existing = $this->userService->getUserByLogin($login);

        if ($existing !== null) {
            return $existing;
        }

        // Auto-provision
        $parts = explode(' ', $name, 2);
        $firstName = $parts[0] !== '' ? $parts[0] : $login;
        $lastName = $parts[1] ?? '';

        $newUser = new User(
            login: $login,
            firstName: $firstName,
            lastName: $lastName,
            email: $email,
            isAdmin: false,
            isEnabled: true,
        );

        try {
            $this->userService->createUser($newUser, $newUser);
            // Set a random password (user authenticates via OAuth)
            $hash = $this->userService->hashPassword(bin2hex(random_bytes(32)));
            $this->userRepository->setPassword($login, $hash);

            return $this->userService->getUserByLogin($login);
        } catch (\Throwable) {
            return null;
        }
    }

    private function getCallbackUrl(Request $request, int $providerId): string
    {
        return $request->getSchemeAndHttpHost() . "/api/v2/auth/oauth/{$providerId}/callback";
    }

    private function normalizeLogin(string $input): string
    {
        // Use the part before @ for email addresses, otherwise use as-is
        $atPos = strpos($input, '@');
        $login = $atPos !== false ? substr($input, 0, $atPos) : $input;

        // Sanitize to valid login format
        $login = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $login);

        return $login !== null && $login !== '' ? strtolower($login) : 'oauth_user';
    }
}
