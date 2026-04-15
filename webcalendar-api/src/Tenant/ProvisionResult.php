<?php

declare(strict_types=1);

namespace App\Tenant;

/**
 * Result of a tenant provisioning operation.
 */
final readonly class ProvisionResult
{
    public function __construct(
        public bool $success,
        public string $slug,
        public string $adminEmail = '',
        #[\SensitiveParameter]
        public string $adminPassword = '',
        public string $error = '',
    ) {
    }

    public static function ok(string $slug, string $adminEmail, #[\SensitiveParameter] string $adminPassword): self
    {
        return new self(success: true, slug: $slug, adminEmail: $adminEmail, adminPassword: $adminPassword);
    }

    public static function fail(string $slug, string $error): self
    {
        return new self(success: false, slug: $slug, error: $error);
    }
}
