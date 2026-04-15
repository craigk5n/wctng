<?php

declare(strict_types=1);

namespace App\Tenant;

/**
 * Entity representing a tenant in the multi-tenant system.
 */
final readonly class Tenant
{
    private const VALID_STATUSES = ['active', 'suspended', 'pending'];

    private const RESERVED_SLUGS = [
        'admin', 'api', 'app', 'blog', 'cdn', 'control', 'dashboard',
        'docs', 'ftp', 'help', 'mail', 'mx', 'ns', 'pop', 'smtp',
        'ssl', 'status', 'support', 'test', 'www',
    ];

    public function __construct(
        private int $id,
        private string $slug,
        private string $name,
        private string $dbHost,
        private string $dbName,
        private string $dbUser,
        #[\SensitiveParameter]
        private string $dbPassword,
        private string $plan,
        private string $status,
        private ?\DateTimeImmutable $createdAt = null,
        private ?\DateTimeImmutable $updatedAt = null,
    ) {
        $this->validateSlug($slug);
        $this->validateStatus($status);
    }

    public function id(): int
    {
        return $this->id;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function dbHost(): string
    {
        return $this->dbHost;
    }

    public function dbName(): string
    {
        return $this->dbName;
    }

    public function dbUser(): string
    {
        return $this->dbUser;
    }

    public function dbPassword(): string
    {
        return $this->dbPassword;
    }

    public function plan(): string
    {
        return $this->plan;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function createdAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function validateSlug(string $slug): void
    {
        if (\strlen($slug) < 3 || \strlen($slug) > 50) {
            throw new \InvalidArgumentException('Tenant slug must be between 3 and 50 characters.');
        }

        if (!preg_match('/^[a-z0-9][a-z0-9-]*[a-z0-9]$/', $slug)) {
            throw new \InvalidArgumentException('Tenant slug must contain only lowercase letters, numbers, and hyphens.');
        }

        if (\in_array($slug, self::RESERVED_SLUGS, true)) {
            throw new \InvalidArgumentException("Tenant slug '{$slug}' is reserved.");
        }
    }

    private function validateStatus(string $status): void
    {
        if (!\in_array($status, self::VALID_STATUSES, true)) {
            throw new \InvalidArgumentException("Invalid tenant status '{$status}'. Valid: " . implode(', ', self::VALID_STATUSES));
        }
    }
}
