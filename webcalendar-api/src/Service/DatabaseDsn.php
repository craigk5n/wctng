<?php

declare(strict_types=1);

namespace App\Service;

/**
 * A DATABASE_URL taken apart into the pieces PDO wants.
 *
 * PdoFactory and ReadinessProbe each had their own copy of this: the same
 * scheme-to-driver match, the same defaults, the same ltrim of the leading
 * slash off the database name. ReadinessProbe's docblock even said it reused
 * PdoFactory "only for DSN assembly", which was not true of the code. The two
 * copies had already drifted -- only one of them patched the missing host into
 * a sqlite URL, and only one of them noticed a URL parse_url could not read.
 *
 * Separate from PdoFactory rather than a method on it because the two callers
 * want different PDO options for the same connection details, and because
 * assembling a DSN is worth being able to test without opening a socket.
 */
final readonly class DatabaseDsn
{
    private const int DEFAULT_MYSQL_PORT = 3306;

    /**
     * @param string $driver one of mysql, pgsql, sqlite
     * @param string $dsn    ready to hand to the PDO constructor
     */
    public function __construct(
        public string $driver,
        public string $dsn,
        public string $user,
        #[\SensitiveParameter]
        public string $password,
    ) {}

    /**
     * @throws \RuntimeException when the URL cannot be parsed at all
     */
    public static function fromUrl(#[\SensitiveParameter] string $databaseUrl): self
    {
        // `parse_url` requires a host after `://`, but `sqlite:///:memory:` and
        // `sqlite:///tmp/db.sqlite` are common DSN forms without one. Patch the
        // host in when it's missing so parse_url accepts the URL (mirrors the
        // same trick doctrine/dbal's DsnParser uses).
        $url = preg_replace('#^((?:pdo-)?sqlite3?):///#', '$1://localhost/', $databaseUrl);
        assert($url !== null);

        /** @var array{scheme?: string, host?: string, port?: int, user?: string, pass?: string, path?: string}|false $parts */
        $parts = parse_url($url);

        if ($parts === false) {
            throw new \RuntimeException('Malformed DATABASE_URL');
        }

        $driver = match ($parts['scheme'] ?? 'mysql') {
            'pgsql', 'postgres', 'postgresql' => 'pgsql',
            'sqlite', 'sqlite3', 'pdo-sqlite', 'pdo-sqlite3' => 'sqlite',
            default => 'mysql',
        };

        if ($driver === 'sqlite') {
            // No credentials to carry: the file path is the whole address.
            return new self($driver, 'sqlite:' . ltrim($parts['path'] ?? '/:memory:', '/'), '', '');
        }

        $host = $parts['host'] ?? 'localhost';
        $port = $parts['port'] ?? self::DEFAULT_MYSQL_PORT;
        $dbname = ltrim($parts['path'] ?? '/webcalendar', '/');

        return new self(
            $driver,
            "{$driver}:host={$host};port={$port};dbname={$dbname};charset=utf8mb4",
            $parts['user'] ?? 'root',
            $parts['pass'] ?? '',
        );
    }
}
