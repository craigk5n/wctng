<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Registry of available authentication providers for the current tenant.
 *
 * Lists enabled providers in priority order. Password auth is always available.
 */
final class AuthProviderRegistry
{
    public function __construct(
        private readonly OAuthProviderRepository $oauthRepo,
        private readonly LdapConfigRepository $ldapRepo,
    ) {}

    /**
     * Returns all enabled auth methods in priority order.
     *
     * @return list<array{type: string, name: string, priority: int, config: array<string, mixed>}>
     */
    public function getEnabledProviders(): array
    {
        $providers = [];

        // Password auth is always available (priority 100 = last)
        $providers[] = [
            'type' => 'password',
            'name' => 'Password',
            'priority' => 100,
            'config' => [],
        ];

        // LDAP (priority 50 = before password)
        $ldap = $this->ldapRepo->get();
        if ($ldap->isEnabled() && $ldap->host() !== '') {
            $providers[] = [
                'type' => 'ldap',
                'name' => 'LDAP',
                'priority' => 50,
                'config' => $ldap->toArray(),
            ];
        }

        // OAuth/OIDC providers (priority 10-49 = first)
        $oauthProviders = $this->oauthRepo->findAll();
        $oauthPriority = 10;
        foreach ($oauthProviders as $p) {
            if ($p->isEnabled()) {
                $providers[] = [
                    'type' => $p->type(),
                    'name' => $p->name(),
                    'priority' => $oauthPriority++,
                    'config' => $p->toArray(),
                ];
            }
        }

        // Sort by priority (lowest = highest priority)
        usort($providers, static fn(array $a, array $b): int => $a['priority'] <=> $b['priority']);

        return $providers;
    }

    /**
     * Returns a summary of auth methods suitable for the login page (no secrets).
     *
     * @return list<array{type: string, name: string}>
     */
    public function getLoginMethods(): array
    {
        return array_map(
            static fn(array $p): array => ['type' => $p['type'], 'name' => $p['name']],
            $this->getEnabledProviders(),
        );
    }
}
