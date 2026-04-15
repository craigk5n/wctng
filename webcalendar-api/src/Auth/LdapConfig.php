<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Entity representing LDAP server configuration.
 */
final readonly class LdapConfig
{
    public function __construct(
        private string $host = '',
        private int $port = 389,
        private string $baseDn = '',
        private string $bindDn = '',
        #[\SensitiveParameter]
        private string $bindPassword = '',
        private string $userFilter = '(uid=%s)',
        private bool $useTls = false,
        private bool $enabled = false,
    ) {
    }

    public function host(): string
    {
        return $this->host;
    }
    public function port(): int
    {
        return $this->port;
    }
    public function baseDn(): string
    {
        return $this->baseDn;
    }
    public function bindDn(): string
    {
        return $this->bindDn;
    }
    public function bindPassword(): string
    {
        return $this->bindPassword;
    }
    public function userFilter(): string
    {
        return $this->userFilter;
    }
    public function useTls(): bool
    {
        return $this->useTls;
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
            'host' => $this->host,
            'port' => $this->port,
            'base_dn' => $this->baseDn,
            'bind_dn' => $this->bindDn,
            'user_filter' => $this->userFilter,
            'use_tls' => $this->useTls,
            'enabled' => $this->enabled,
        ];
    }
}
