<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Auth\OAuthProvider;
use App\Auth\OAuthProviderRepository;
use App\Response\ApiResponse;
use App\Security\OutboundUrlValidator;
use App\Security\WebCalendarUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class AuthProviderController
{
    public function __construct(
        private readonly OAuthProviderRepository $repository,
        private readonly OutboundUrlValidator $urlValidator,
    ) {}

    /**
     * OAuthController fetches token_url and userinfo_url server-side, carrying
     * the client secret and access token, so a rejected target must not be
     * storable. auth_url is only ever handed to the browser, but the scheme
     * rules apply to it for the same reason they apply anywhere else.
     *
     * Empty is allowed: the fields are optional and default to ''.
     *
     * @param array<string, string> $urls field name => URL
     */
    private function rejectUnsafeUrls(array $urls): ?JsonResponse
    {
        foreach ($urls as $field => $url) {
            if ($url === '') {
                continue;
            }

            try {
                $this->urlValidator->validate($url);
            } catch (\InvalidArgumentException $e) {
                return ApiResponse::error(400, sprintf('%s: %s', $field, $e->getMessage()));
            }
        }

        return null;
    }

    /**
     * Public endpoint: returns enabled providers (name + id only, no secrets).
     */
    #[Route('/api/v2/auth/oauth/providers', name: 'api_auth_providers_public', methods: ['GET'])]
    public function publicList(): JsonResponse
    {
        $providers = $this->repository->findAll();
        $items = [];
        foreach ($providers as $p) {
            if ($p->isEnabled()) {
                $items[] = ['id' => $p->id(), 'name' => $p->name(), 'type' => $p->type()];
            }
        }

        return ApiResponse::success($items);
    }

    #[Route('/api/v2/admin/auth-providers', name: 'api_auth_providers_list', methods: ['GET'])]
    public function list(#[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null || !$user->getCoreUser()->isAdmin()) {
            return ApiResponse::error(403, 'Admin access required');
        }

        $providers = $this->repository->findAll();
        $items = array_map(static fn(OAuthProvider $p): array => $p->toArray(), $providers);

        return ApiResponse::success(array_values($items));
    }

    #[Route('/api/v2/admin/auth-providers', name: 'api_auth_providers_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null || !$user->getCoreUser()->isAdmin()) {
            return ApiResponse::error(403, 'Admin access required');
        }

        $decoded = json_decode($request->getContent(), true);
        if (!\is_array($decoded)) {
            return ApiResponse::error(400, 'Invalid JSON body');
        }

        /** @var array<string, mixed> $data */
        $data = $decoded;

        $name = isset($data['name']) && \is_string($data['name']) ? $data['name'] : null;
        $clientId = isset($data['client_id']) && \is_string($data['client_id']) ? $data['client_id'] : null;

        if ($name === null || $name === '' || $clientId === null || $clientId === '') {
            return ApiResponse::error(400, 'Missing required fields: name, client_id');
        }

        $authUrl = isset($data['auth_url']) && \is_string($data['auth_url']) ? $data['auth_url'] : '';
        $tokenUrl = isset($data['token_url']) && \is_string($data['token_url']) ? $data['token_url'] : '';
        $userinfoUrl = isset($data['userinfo_url']) && \is_string($data['userinfo_url']) ? $data['userinfo_url'] : '';

        $rejected = $this->rejectUnsafeUrls([
            'auth_url' => $authUrl,
            'token_url' => $tokenUrl,
            'userinfo_url' => $userinfoUrl,
        ]);

        if ($rejected !== null) {
            return $rejected;
        }

        $provider = new OAuthProvider(
            id: 0,
            name: $name,
            type: isset($data['type']) && \is_string($data['type']) ? $data['type'] : 'oauth2',
            clientId: $clientId,
            clientSecret: isset($data['client_secret']) && \is_string($data['client_secret']) ? $data['client_secret'] : '',
            authUrl: $authUrl,
            tokenUrl: $tokenUrl,
            userinfoUrl: $userinfoUrl,
            scopes: isset($data['scopes']) && \is_string($data['scopes']) ? $data['scopes'] : '',
            enabled: !isset($data['enabled']) || (bool) $data['enabled'],
        );

        $id = $this->repository->save($provider);
        $saved = $this->repository->findById($id);

        return ApiResponse::success($saved?->toArray(), null, Response::HTTP_CREATED);
    }

    #[Route('/api/v2/admin/auth-providers/{id}', name: 'api_auth_providers_update', methods: ['PUT'])]
    public function update(int $id, Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null || !$user->getCoreUser()->isAdmin()) {
            return ApiResponse::error(403, 'Admin access required');
        }

        $existing = $this->repository->findById($id);
        if ($existing === null) {
            return ApiResponse::error(404, 'Provider not found');
        }

        $decoded = json_decode($request->getContent(), true);
        if (!\is_array($decoded)) {
            return ApiResponse::error(400, 'Invalid JSON body');
        }

        /** @var array<string, mixed> $data */
        $data = $decoded;

        $authUrl = isset($data['auth_url']) && \is_string($data['auth_url']) ? $data['auth_url'] : $existing->authUrl();
        $tokenUrl = isset($data['token_url']) && \is_string($data['token_url']) ? $data['token_url'] : $existing->tokenUrl();
        $userinfoUrl = isset($data['userinfo_url']) && \is_string($data['userinfo_url']) ? $data['userinfo_url'] : $existing->userinfoUrl();

        $rejected = $this->rejectUnsafeUrls([
            'auth_url' => $authUrl,
            'token_url' => $tokenUrl,
            'userinfo_url' => $userinfoUrl,
        ]);

        if ($rejected !== null) {
            return $rejected;
        }

        $updated = new OAuthProvider(
            id: $id,
            name: isset($data['name']) && \is_string($data['name']) ? $data['name'] : $existing->name(),
            type: isset($data['type']) && \is_string($data['type']) ? $data['type'] : $existing->type(),
            clientId: isset($data['client_id']) && \is_string($data['client_id']) ? $data['client_id'] : $existing->clientId(),
            clientSecret: isset($data['client_secret']) && \is_string($data['client_secret']) ? $data['client_secret'] : $existing->clientSecret(),
            authUrl: $authUrl,
            tokenUrl: $tokenUrl,
            userinfoUrl: $userinfoUrl,
            scopes: isset($data['scopes']) && \is_string($data['scopes']) ? $data['scopes'] : $existing->scopes(),
            enabled: isset($data['enabled']) ? (bool) $data['enabled'] : $existing->isEnabled(),
        );

        $this->repository->save($updated);

        return ApiResponse::success($updated->toArray());
    }

    #[Route('/api/v2/admin/auth-providers/{id}', name: 'api_auth_providers_delete', methods: ['DELETE'])]
    public function delete(int $id, #[CurrentUser] ?WebCalendarUser $user): Response
    {
        if ($user === null || !$user->getCoreUser()->isAdmin()) {
            return ApiResponse::error(403, 'Admin access required');
        }

        $this->repository->delete($id);

        return ApiResponse::noContent();
    }
}
