<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Creates a PDO connection from a Symfony-style DATABASE_URL.
 *
 * Supports: mysql://user:pass@host:port/dbname
 */
final class PdoFactory
{
    public static function createFromUrl(string $databaseUrl): \PDO
    {
        /** @var array{scheme?: string, host?: string, port?: int, user?: string, pass?: string, path?: string} $parts */
        $parts = parse_url($databaseUrl);

        $scheme = $parts['scheme'] ?? 'mysql';
        $host = $parts['host'] ?? 'localhost';
        $port = $parts['port'] ?? 3306;
        $user = $parts['user'] ?? 'root';
        $pass = $parts['pass'] ?? '';
        $dbname = ltrim($parts['path'] ?? '/webcalendar', '/');

        $driver = match ($scheme) {
            'mysql', 'mysql2' => 'mysql',
            'pgsql', 'postgres', 'postgresql' => 'pgsql',
            'sqlite', 'sqlite3' => 'sqlite',
            default => 'mysql',
        };

        if ($driver === 'sqlite') {
            $dsn = "sqlite:{$dbname}";

            return new \PDO($dsn, options: [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
        }

        $dsn = "{$driver}:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";

        return new \PDO($dsn, $user, $pass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
