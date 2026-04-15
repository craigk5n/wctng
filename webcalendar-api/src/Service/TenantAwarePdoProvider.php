<?php

declare(strict_types=1);

namespace App\Service;

use App\Tenant\TenantContext;
use App\Tenant\TenantDatabaseManager;

/**
 * Resolves the correct PDO connection for the current request: the
 * tenant DB when a tenant is active, otherwise the default DB.
 *
 * Extracted from `CoreServiceFactory::getPdo()` (PBP-S4) so controllers
 * and services that need a tenant-aware handle can depend on a narrow
 * interface instead of the whole factory. Controllers that hit the
 * default DB regardless of tenancy (like `/health`) keep injecting
 * `@pdo.connection` directly.
 */
final readonly class TenantAwarePdoProvider
{
    public function __construct(
        private \PDO $defaultPdo,
        private ?TenantContext $tenantContext = null,
        private ?TenantDatabaseManager $tenantDbManager = null,
    ) {}

    public function get(): \PDO
    {
        if ($this->tenantContext !== null && $this->tenantDbManager !== null) {
            $tenant = $this->tenantContext->getTenant();
            if ($tenant !== null) {
                return $this->tenantDbManager->getConnection($tenant);
            }
        }

        return $this->defaultPdo;
    }
}
