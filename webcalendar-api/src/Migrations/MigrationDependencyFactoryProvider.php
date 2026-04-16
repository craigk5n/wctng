<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;

/**
 * Builds a Doctrine `DependencyFactory` programmatically from `DATABASE_URL`
 * and the fixed migration layout under `migrations/api/`.
 *
 * We avoid the `doctrine/doctrine-migrations-bundle` because the wider app
 * talks to the DB through raw PDO; bringing in the full bundle would pull
 * `doctrine-bundle` + ORM discovery for two migration files. Wiring the
 * `DependencyFactory` directly into `services.yaml` is proportional.
 *
 * Config pinned here (kept in code rather than YAML because it's tiny and
 * lives beside its consumer):
 * - Namespace: `App\Migrations`
 * - Path:      `<project-root>/migrations/api/`
 * - Version storage table: `api_doctrine_migration_versions` — namespaced
 *   so it cannot collide with anything webcalendar-core (the legacy init-
 *   script schema manager) might ever create.
 */
final readonly class MigrationDependencyFactoryProvider
{
    public const VERSION_TABLE = 'api_doctrine_migration_versions';
    public const NAMESPACE = 'App\\Migrations';

    public function __construct(
        #[\SensitiveParameter]
        private string $databaseUrl,
        private string $projectDir,
    ) {}

    public function create(): DependencyFactory
    {
        $config = new ConfigurationArray([
            'migrations_paths' => [
                self::NAMESPACE => $this->projectDir . '/migrations/api',
            ],
            'table_storage' => [
                'table_name' => self::VERSION_TABLE,
            ],
            'all_or_nothing' => true,
            'check_database_platform' => false,
            'transactional' => true,
        ]);

        return DependencyFactory::fromConnection($config, new ExistingConnection($this->connection()));
    }

    private function connection(): Connection
    {
        $params = (new DsnParser(['mysql' => 'pdo_mysql', 'sqlite' => 'pdo_sqlite', 'postgresql' => 'pdo_pgsql']))
            ->parse($this->databaseUrl);

        return DriverManager::getConnection($params);
    }
}
