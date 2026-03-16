<?php

declare(strict_types=1);

namespace App\Tenant;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Request-scoped service holding the current tenant context.
 *
 * In standalone mode, the tenant is null. In hosted/multi-tenant mode,
 * the tenant is set by the TenantResolverListener early in the request.
 */
final class TenantContext implements ResetInterface
{
    private ?Tenant $tenant = null;

    public function setTenant(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    /**
     * Returns the current tenant or throws if none is set.
     *
     * @throws \RuntimeException If no tenant is set
     */
    public function getTenantOrFail(): Tenant
    {
        if ($this->tenant === null) {
            throw new \RuntimeException('No tenant context set. Are you in standalone mode?');
        }

        return $this->tenant;
    }

    /**
     * Whether a tenant is currently resolved (multi-tenant mode).
     */
    public function isMultiTenant(): bool
    {
        return $this->tenant !== null;
    }

    /**
     * Reset state between requests (called by Symfony's service resetter).
     */
    #[\Override]
    public function reset(): void
    {
        $this->tenant = null;
    }
}
