<?php

declare(strict_types=1);

namespace App\Tenant;

use App\Service\DatabaseDsn;

/**
 * Creates a tenant's MySQL database and login.
 *
 * Needs credentials that can CREATE DATABASE and CREATE USER, which the
 * application's own connection deliberately does not have. They come from
 * TENANT_ADMIN_DATABASE_URL, in the same form as DATABASE_URL; without it
 * provisioning reports that it is not configured rather than half-failing
 * somewhere further in.
 */
final readonly class MySqlTenantDatabaseCreator implements TenantDatabaseCreator
{
    /**
     * MySQL caps identifiers at 64 characters. The names this receives are
     * built from a validated tenant slug, but they are interpolated into DDL
     * -- which takes no bound parameters -- so they are checked here as well
     * rather than trusted to have arrived intact.
     */
    private const int MAX_IDENTIFIER_LENGTH = 64;

    public function __construct(
        #[\SensitiveParameter]
        private string $adminDatabaseUrl,
        private string $grantHost = '%',
    ) {}

    #[\Override]
    public function create(string $dbName, string $dbUser, #[\SensitiveParameter] string $dbPassword): void
    {
        self::assertUsableIdentifier($dbName, 'database name');
        self::assertUsableIdentifier($dbUser, 'database user');

        if ($this->adminDatabaseUrl === '') {
            throw new \RuntimeException(
                'Cannot create a tenant database: set TENANT_ADMIN_DATABASE_URL to a login that may CREATE DATABASE and CREATE USER.',
            );
        }

        $admin = DatabaseDsn::fromUrl($this->adminDatabaseUrl);

        try {
            $pdo = new \PDO($admin->dsn, $admin->user, $admin->password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);

            // Identifiers cannot be bound, so they are back-quoted after the
            // check above; the password can be, and is, quoted by the driver.
            $database = self::quoteIdentifier($dbName);
            $user = self::quoteIdentifier($dbUser);
            $host = self::quoteIdentifier($this->grantHost);
            $secret = $pdo->quote($dbPassword);

            $pdo->exec("CREATE DATABASE IF NOT EXISTS {$database} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("CREATE USER IF NOT EXISTS {$user}@{$host} IDENTIFIED BY {$secret}");
            $pdo->exec("GRANT ALL PRIVILEGES ON {$database}.* TO {$user}@{$host}");
            $pdo->exec('FLUSH PRIVILEGES');
        } catch (\PDOException $e) {
            throw new \RuntimeException("Could not create tenant database '{$dbName}': {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Back-quoted, with any back-quote inside doubled. Belt and braces on top
     * of the character check: an identifier reaching here is already known to
     * hold nothing but word characters.
     */
    private static function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private static function assertUsableIdentifier(string $identifier, string $what): void
    {
        if ($identifier === '' || \strlen($identifier) > self::MAX_IDENTIFIER_LENGTH) {
            throw new \RuntimeException(
                \sprintf('Invalid %s: must be 1 to %d characters.', $what, self::MAX_IDENTIFIER_LENGTH),
            );
        }

        if (preg_match('/^[A-Za-z0-9_]+$/', $identifier) !== 1) {
            throw new \RuntimeException(
                \sprintf("Invalid %s '%s': only letters, digits and underscores are allowed.", $what, $identifier),
            );
        }
    }
}
