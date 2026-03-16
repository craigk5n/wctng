<?php

declare(strict_types=1);

namespace App\Tenant;

use App\Service\CoreServiceFactory;
use WebCalendar\Core\Domain\Entity\User;

/**
 * Provisions new tenants: creates DB, deploys schema, creates admin user.
 */
final class TenantProvisioner
{
    public function __construct(
        private readonly TenantRepository $tenantRepository,
        private readonly TenantDatabaseManager $dbManager,
        private readonly string $dbDriver = 'mysql',
    ) {
    }

    /**
     * Provisions a new tenant with database, schema, and admin user.
     */
    public function provision(string $slug, string $name, string $adminEmail, string $plan = 'free'): ProvisionResult
    {
        // Validate slug (Tenant constructor validates format + reserved)
        try {
            $testTenant = new Tenant(0, $slug, $name, '', '', '', '', $plan, 'pending');
        } catch (\InvalidArgumentException $e) {
            return ProvisionResult::fail($slug, $e->getMessage());
        }

        // Check for duplicate
        if ($this->tenantRepository->findBySlug($slug) !== null) {
            return ProvisionResult::fail($slug, "Tenant with slug '{$slug}' already exists.");
        }

        $dbName = 'wc_tenant_' . str_replace('-', '_', $slug);
        $dbUser = 'wc_' . str_replace('-', '_', $slug);
        $adminPassword = $this->generatePassword();

        try {
            if ($this->dbDriver === 'sqlite') {
                // SQLite: use in-memory or file-based DB (for testing)
                $dbHost = '';
                $dbPassword = '';

                $tenant = new Tenant(
                    id: 0,
                    slug: $slug,
                    name: $name,
                    dbHost: $dbHost,
                    dbName: ':memory:',
                    dbUser: '',
                    dbPassword: '',
                    plan: $plan,
                    status: 'active',
                );
            } else {
                // MySQL: create database and user
                $dbHost = $this->getDefaultDbHost();
                $dbPassword = $this->generatePassword();
                $encryptedPassword = $this->dbManager->encryptPassword($dbPassword);

                $this->createMySqlDatabase($dbName, $dbUser, $dbPassword);

                $tenant = new Tenant(
                    id: 0,
                    slug: $slug,
                    name: $name,
                    dbHost: $dbHost,
                    dbName: $dbName,
                    dbUser: $dbUser,
                    dbPassword: $encryptedPassword,
                    plan: $plan,
                    status: 'active',
                );
            }

            // Save tenant to registry
            $tenantId = $this->tenantRepository->save($tenant);

            // Deploy schema
            $tenantPdo = $this->dbManager->getConnection($tenant);
            $this->deploySchema($tenantPdo);

            // Create admin user
            $this->createAdminUser($tenantPdo, $adminEmail, $adminPassword);

            return ProvisionResult::ok($slug, $adminEmail, $adminPassword);
        } catch (\Throwable $e) {
            return ProvisionResult::fail($slug, 'Provisioning failed: ' . $e->getMessage());
        }
    }

    private function deploySchema(\PDO $pdo): void
    {
        $schemaFile = $this->getSchemaFilePath();

        if (!file_exists($schemaFile)) {
            throw new \RuntimeException("Schema file not found: {$schemaFile}");
        }

        $sql = file_get_contents($schemaFile);
        if ($sql === false) {
            throw new \RuntimeException("Could not read schema file: {$schemaFile}");
        }

        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            static fn (string $s): bool => $s !== '',
        );

        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }
    }

    private function createAdminUser(\PDO $pdo, string $email, string $password): void
    {
        $login = 'admin';
        $hash = password_hash($password, PASSWORD_BCRYPT);

        $pdo->prepare(
            "INSERT INTO webcal_user (cal_login, cal_firstname, cal_lastname, cal_email, cal_is_admin, cal_enabled, cal_passwd)
             VALUES (:login, 'Admin', 'User', :email, 'Y', 'Y', :hash)",
        )->execute(['login' => $login, 'email' => $email, 'hash' => $hash]);
    }

    private function getSchemaFilePath(): string
    {
        $reflection = new \ReflectionClass(\WebCalendar\Core\Application\Service\EventService::class);
        $coreDir = \dirname((string) $reflection->getFileName(), 4);

        $fileName = match ($this->dbDriver) {
            'pgsql' => 'postgresql-schema.sql',
            'sqlite' => 'sqlite-schema.sql',
            default => 'mysql-schema.sql',
        };

        return $coreDir . '/src/Infrastructure/Persistence/' . $fileName;
    }

    private function createMySqlDatabase(string $dbName, string $dbUser, string $dbPassword): void
    {
        // This would use a root/admin PDO connection to create the DB
        // For now, this is a placeholder — real implementation needs root credentials
        throw new \RuntimeException('MySQL database creation requires root PDO (not yet implemented for tests)');
    }

    private function getDefaultDbHost(): string
    {
        return 'mysql'; // Docker service name
    }

    private function generatePassword(): string
    {
        return bin2hex(random_bytes(16));
    }
}
