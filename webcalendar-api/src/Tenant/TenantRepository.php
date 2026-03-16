<?php

declare(strict_types=1);

namespace App\Tenant;

/**
 * Repository for tenant CRUD operations in the control database.
 */
final readonly class TenantRepository
{
    /**
     * SQL schema for the tenants table (SQLite-compatible for tests, MySQL-compatible for production).
     */
    public const SCHEMA_SQL = <<<'SQL'
        CREATE TABLE IF NOT EXISTS tenants (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slug VARCHAR(50) NOT NULL UNIQUE,
            name VARCHAR(255) NOT NULL,
            db_host VARCHAR(255) NOT NULL DEFAULT '',
            db_name VARCHAR(255) NOT NULL DEFAULT '',
            db_user VARCHAR(255) NOT NULL DEFAULT '',
            db_password TEXT NOT NULL,
            plan VARCHAR(50) NOT NULL DEFAULT 'free',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    SQL;

    /**
     * MySQL-specific schema (uses AUTO_INCREMENT instead of AUTOINCREMENT).
     */
    public const SCHEMA_SQL_MYSQL = <<<'SQL'
        CREATE TABLE IF NOT EXISTS tenants (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(50) NOT NULL UNIQUE,
            name VARCHAR(255) NOT NULL,
            db_host VARCHAR(255) NOT NULL DEFAULT '',
            db_name VARCHAR(255) NOT NULL DEFAULT '',
            db_user VARCHAR(255) NOT NULL DEFAULT '',
            db_password TEXT NOT NULL,
            plan VARCHAR(50) NOT NULL DEFAULT 'free',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    SQL;

    public function __construct(
        private \PDO $pdo,
    ) {
    }

    public function findBySlug(string $slug): ?Tenant
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tenants WHERE slug = :slug');
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!\is_array($row)) {
            return null;
        }

        /** @var array<string, mixed> $row */
        return $this->mapRow($row);
    }

    /**
     * @return Tenant[]
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM tenants ORDER BY slug');
        if ($stmt === false) {
            return [];
        }

        $tenants = [];
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        while (\is_array($row)) {
            $tenants[] = $this->mapRow($row);
            /** @var array<string, mixed>|false $row */
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        }

        return $tenants;
    }

    /**
     * Saves a tenant (insert or update). Returns the tenant ID.
     */
    public function save(Tenant $tenant): int
    {
        if ($tenant->id() > 0) {
            $this->pdo->prepare(
                'UPDATE tenants SET name = :name, db_host = :db_host, db_name = :db_name,
                 db_user = :db_user, db_password = :db_password, plan = :plan, status = :status,
                 updated_at = CURRENT_TIMESTAMP WHERE id = :id',
            )->execute([
                'id' => $tenant->id(),
                'name' => $tenant->name(),
                'db_host' => $tenant->dbHost(),
                'db_name' => $tenant->dbName(),
                'db_user' => $tenant->dbUser(),
                'db_password' => $tenant->dbPassword(),
                'plan' => $tenant->plan(),
                'status' => $tenant->status(),
            ]);

            return $tenant->id();
        }

        $this->pdo->prepare(
            'INSERT INTO tenants (slug, name, db_host, db_name, db_user, db_password, plan, status)
             VALUES (:slug, :name, :db_host, :db_name, :db_user, :db_password, :plan, :status)',
        )->execute([
            'slug' => $tenant->slug(),
            'name' => $tenant->name(),
            'db_host' => $tenant->dbHost(),
            'db_name' => $tenant->dbName(),
            'db_user' => $tenant->dbUser(),
            'db_password' => $tenant->dbPassword(),
            'plan' => $tenant->plan(),
            'status' => $tenant->status(),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM tenants WHERE id = :id')->execute(['id' => $id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): Tenant
    {
        /** @var string $slug */
        $slug = $row['slug'] ?? '';
        /** @var string $name */
        $name = $row['name'] ?? '';
        /** @var string $dbHost */
        $dbHost = $row['db_host'] ?? '';
        /** @var string $dbName */
        $dbName = $row['db_name'] ?? '';
        /** @var string $dbUser */
        $dbUser = $row['db_user'] ?? '';
        /** @var string $dbPassword */
        $dbPassword = $row['db_password'] ?? '';
        /** @var string $plan */
        $plan = $row['plan'] ?? 'free';
        /** @var string $status */
        $status = $row['status'] ?? 'pending';
        /** @var string|null $createdAtStr */
        $createdAtStr = $row['created_at'] ?? null;
        /** @var string|null $updatedAtStr */
        $updatedAtStr = $row['updated_at'] ?? null;

        $createdAt = $createdAtStr !== null ? new \DateTimeImmutable($createdAtStr) : null;
        $updatedAt = $updatedAtStr !== null ? new \DateTimeImmutable($updatedAtStr) : null;

        return new Tenant(
            id: \is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
            slug: $slug,
            name: $name,
            dbHost: $dbHost,
            dbName: $dbName,
            dbUser: $dbUser,
            dbPassword: $dbPassword,
            plan: $plan,
            status: $status,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        );
    }
}
