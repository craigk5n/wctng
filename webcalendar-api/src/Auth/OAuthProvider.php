<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Entity representing an OAuth2/OIDC provider configuration.
 */
final readonly class OAuthProvider
{
    public function __construct(
        private int $id,
        private string $name,
        private string $type, // 'oauth2' or 'oidc'
        private string $clientId,
        #[\SensitiveParameter]
        private string $clientSecret,
        private string $authUrl,
        private string $tokenUrl,
        private string $userinfoUrl,
        private string $scopes,
        private bool $enabled = true,
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }
    public function name(): string
    {
        return $this->name;
    }
    public function type(): string
    {
        return $this->type;
    }
    public function clientId(): string
    {
        return $this->clientId;
    }
    public function clientSecret(): string
    {
        return $this->clientSecret;
    }
    public function authUrl(): string
    {
        return $this->authUrl;
    }
    public function tokenUrl(): string
    {
        return $this->tokenUrl;
    }
    public function userinfoUrl(): string
    {
        return $this->userinfoUrl;
    }
    public function scopes(): string
    {
        return $this->scopes;
    }
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'client_id' => $this->clientId,
            'auth_url' => $this->authUrl,
            'token_url' => $this->tokenUrl,
            'userinfo_url' => $this->userinfoUrl,
            'scopes' => $this->scopes,
            'enabled' => $this->enabled,
        ];
    }
}
