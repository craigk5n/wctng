<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\CoreServiceFactory;
use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Domain\Entity\User;

/**
 * Base class for integration tests using SQLite in-memory database.
 * Seeds the schema and creates a test admin user.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected \PDO $pdo;
    protected CoreServiceFactory $factory;
    protected User $adminUser;
    protected User $normalUser;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Load SQLite schema from vendor (installed via Composer from GitHub)
        $schemaPath = realpath(__DIR__ . '/../../vendor/craigk5n/webcalendar-core/src/Infrastructure/Persistence/sqlite-schema.sql');

        if ($schemaPath !== false) {
            $schema = file_get_contents($schemaPath);
            if ($schema !== false) {
                // Remove comment lines
                $clean = (string) preg_replace('/--[^\n]*/', '', $schema);
                // Split on semicolons followed by whitespace/newline
                /** @var string[] $statements */
                $statements = preg_split('/;\s*\n/', $clean) ?? [];
                foreach ($statements as $stmt) {
                    $stmt = trim($stmt);
                    if ($stmt !== '') {
                        try {
                            $this->pdo->exec($stmt);
                        } catch (\PDOException) {
                            // Skip statements that fail
                        }
                    }
                }
            }
        }

        $this->factory = new CoreServiceFactory($this->pdo, 'test_secret');

        // Create test users
        $this->adminUser = new User('admin', 'Admin', 'User', 'admin@test.com', true, true);
        $this->normalUser = new User('alice', 'Alice', 'Smith', 'alice@test.com', false, true);

        $userService = $this->factory->getUserService();
        $userService->createUser($this->adminUser, $this->adminUser);
        // Set password for admin
        $this->factory->getUserRepository()->setPassword('admin', password_hash('admin', \PASSWORD_DEFAULT));

        $userService->createUser($this->normalUser, $this->adminUser);
        $this->factory->getUserRepository()->setPassword('alice', password_hash('password', \PASSWORD_DEFAULT));
    }
}
