<?php

declare(strict_types=1);

namespace App\Tenant;

/**
 * Entity representing a tenant in the multi-tenant system.
 */
final readonly class Tenant
{
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
        private TenantPlan $plan,
        private TenantStatus $status,
        private ?\DateTimeImmutable $createdAt = null,
        private ?\DateTimeImmutable $updatedAt = null,
    ) {
        $this->validateSlug($slug);
    }

    /**
     * Static factory for hydrating from a DB row — handles the string
     * → enum conversion at the persistence boundary. An unknown
     * plan/status string throws `DomainException` (surfacing schema
     * drift early) rather than silently defaulting, because a tenant
     * whose plan we can't reason about is worse than a loud failure.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $plan = self::parsePlan(self::stringField($row, 'plan', 'free'));
        $status = self::parseStatus(self::stringField($row, 'status', 'pending'));

        $createdAtStr = $row['created_at'] ?? null;
        $updatedAtStr = $row['updated_at'] ?? null;

        return new self(
            id: \is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
            slug: self::stringField($row, 'slug', ''),
            name: self::stringField($row, 'name', ''),
            dbHost: self::stringField($row, 'db_host', ''),
            dbName: self::stringField($row, 'db_name', ''),
            dbUser: self::stringField($row, 'db_user', ''),
            dbPassword: self::stringField($row, 'db_password', ''),
            plan: $plan,
            status: $status,
            createdAt: \is_string($createdAtStr) ? new \DateTimeImmutable($createdAtStr) : null,
            updatedAt: \is_string($updatedAtStr) ? new \DateTimeImmutable($updatedAtStr) : null,
        );
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

    public function plan(): TenantPlan
    {
        return $this->plan;
    }

    public function status(): TenantStatus
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return $this->status === TenantStatus::Active;
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

    private static function parseStatus(string $value): TenantStatus
    {
        $status = TenantStatus::tryFrom($value);
        if ($status === null) {
            $valid = implode(', ', array_map(static fn(TenantStatus $s) => $s->value, TenantStatus::cases()));

            throw new \DomainException("Invalid tenant status '{$value}'. Valid: {$valid}");
        }

        return $status;
    }

    private static function parsePlan(string $value): TenantPlan
    {
        $plan = TenantPlan::tryFrom($value);
        if ($plan === null) {
            $valid = implode(', ', array_map(static fn(TenantPlan $p) => $p->value, TenantPlan::cases()));

            throw new \DomainException("Invalid tenant plan '{$value}'. Valid: {$valid}");
        }

        return $plan;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function stringField(array $row, string $key, string $default): string
    {
        $value = $row[$key] ?? null;

        return \is_string($value) ? $value : $default;
    }
}
