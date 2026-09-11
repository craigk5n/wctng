<?php

declare(strict_types=1);

namespace App\Tenant;

/**
 * Runs schema migrations across tenant databases.
 *
 * Migrations are SQL files in the migrations directory, named like:
 *   001_add_column.sql
 *   002_create_table.sql
 *
 * A `schema_migrations` table tracks which migrations have been applied.
 */
final class TenantMigrator
{
    /** @var list<array{version: string, file: string}> */
    private array $availableMigrations = [];

    public function __construct(
        private readonly TenantRepository $tenantRepository,
        private readonly TenantDatabaseManager $dbManager,
        private readonly string $migrationsDir,
    ) {
        $this->loadAvailableMigrations();
    }

    /**
     * Migrate a single tenant. Returns a result summary.
     *
     * @return array{slug: string, applied: int, skipped: int, error: string|null}
     */
    public function migrateTenant(Tenant $tenant): array
    {
        try {
            $pdo = $this->dbManager->getConnection($tenant);
        } catch (\RuntimeException $e) {
            return ['slug' => $tenant->slug(), 'applied' => 0, 'skipped' => 0, 'error' => $e->getMessage()];
        }

        try {
            $this->ensureMigrationsTable($pdo);
            $applied = $this->getAppliedMigrations($pdo);
        } catch (\PDOException $e) {
            // Reading or creating the history can fail on its own -- the login
            // may not create tables, or a schema_migrations left by another
            // tool may have nothing this recognises in it. Report it like any
            // other migration failure: throwing here would break the return
            // contract and, through migrateAll(), abandon every tenant after
            // this one.
            return [
                'slug' => $tenant->slug(),
                'applied' => 0,
                'skipped' => 0,
                'error' => "Migration history unavailable: {$e->getMessage()}",
            ];
        }

        $newApplied = 0;
        $skipped = 0;

        foreach ($this->availableMigrations as $migration) {
            if (\in_array($migration['version'], $applied, true)) {
                $skipped++;
                continue;
            }

            try {
                $sql = file_get_contents($migration['file']);
                if ($sql === false) {
                    return ['slug' => $tenant->slug(), 'applied' => $newApplied, 'skipped' => $skipped, 'error' => "Could not read: {$migration['file']}"];
                }

                $statements = array_filter(
                    array_map('trim', explode(';', $sql)),
                    static fn(string $s): bool => $s !== '',
                );

                foreach ($statements as $statement) {
                    $pdo->exec($statement);
                }

                $pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (:version, CURRENT_TIMESTAMP)')
                    ->execute(['version' => $migration['version']]);

                $newApplied++;
            } catch (\PDOException $e) {
                return ['slug' => $tenant->slug(), 'applied' => $newApplied, 'skipped' => $skipped, 'error' => "Migration {$migration['version']} failed: {$e->getMessage()}"];
            }
        }

        return ['slug' => $tenant->slug(), 'applied' => $newApplied, 'skipped' => $skipped, 'error' => null];
    }

    /**
     * Migrate all active tenants.
     *
     * @return list<array{slug: string, applied: int, skipped: int, error: string|null}>
     */
    public function migrateAll(): array
    {
        $results = [];
        $tenants = $this->tenantRepository->findAll();

        foreach ($tenants as $tenant) {
            if (!$tenant->isActive()) {
                $results[] = ['slug' => $tenant->slug(), 'applied' => 0, 'skipped' => 0, 'error' => 'Skipped (suspended)'];
                continue;
            }
            $results[] = $this->migrateTenant($tenant);
        }

        return $results;
    }

    /**
     * @return int Number of available migration files
     */
    public function availableCount(): int
    {
        return \count($this->availableMigrations);
    }

    private function ensureMigrationsTable(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(100) NOT NULL PRIMARY KEY,
                applied_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )',
        );
    }

    /**
     * @return list<string>
     */
    private function getAppliedMigrations(\PDO $pdo): array
    {
        $stmt = $pdo->query('SELECT version FROM schema_migrations ORDER BY version');
        if ($stmt === false) {
            return [];
        }

        $versions = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            if (\is_array($row) && isset($row['version']) && \is_string($row['version'])) {
                $versions[] = $row['version'];
            }
        }

        return $versions;
    }

    private function loadAvailableMigrations(): void
    {
        if (!is_dir($this->migrationsDir)) {
            return;
        }

        $files = glob($this->migrationsDir . '/*.sql');
        if ($files === false) {
            return;
        }

        sort($files);

        foreach ($files as $file) {
            $basename = basename($file, '.sql');
            $this->availableMigrations[] = ['version' => $basename, 'file' => $file];
        }
    }
}
