<?php

declare(strict_types=1);

namespace App\Tenant;

/**
 * Creates the database and login a new tenant will use.
 *
 * A seam, like WebhookTransport over curl: the DDL needs administrative
 * credentials and a live server, so it lives in one thin implementation and
 * everything the provisioner decides around it stays testable.
 */
interface TenantDatabaseCreator
{
    /**
     * Creates the database and a login with rights over it, if they are not
     * already there. Safe to call twice for the same tenant.
     *
     * @throws \RuntimeException if the names are not usable as identifiers, if
     *                           no administrative credentials are configured,
     *                           or if the server refuses the DDL
     */
    public function create(string $dbName, string $dbUser, #[\SensitiveParameter] string $dbPassword): void;
}
