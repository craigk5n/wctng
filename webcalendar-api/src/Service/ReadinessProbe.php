<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Short-lived, timeout-bounded DB ping for readiness checks (PBP-S13).
 *
 * Key design choices:
 * - Builds a *fresh* PDO connection per ping with `ATTR_TIMEOUT=1` so the
 *   connect() itself can't block indefinitely when the DB is
 *   unreachable-but-not-down.
 * - On MySQL, sets `MAX_EXECUTION_TIME=100` (ms) before the `SELECT 1` so
 *   a slow-responding server returns within 100ms instead of hanging.
 *   MAX_EXECUTION_TIME only applies to SELECTs, which is what we need.
 * - Reuses {@see PdoFactory} only for DSN assembly — we can't reuse the
 *   shared app PDO because overriding its timeout would taint subsequent
 *   queries on the same connection.
 *
 * This is a ~2KB service lifted out of `HealthController` so the probe
 * stays testable in isolation.
 */
final class ReadinessProbe
{
    private const CONNECT_TIMEOUT_SECONDS = 1;
    private const QUERY_MAX_EXECUTION_TIME_MS = 100;

    public function __construct(
        #[\SensitiveParameter]
        private readonly string $databaseUrl,
    ) {}

    public function pingDatabase(): DbProbeResult
    {
        $start = microtime(true);

        try {
            $pdo = $this->openProbeConnection();
            $pdo->query('SELECT 1');
        } catch (\Throwable) {
            return new DbProbeResult(ok: false, latencyMs: null);
        }

        $latencyMs = (int) round((microtime(true) - $start) * 1000);

        return new DbProbeResult(ok: true, latencyMs: $latencyMs);
    }

    private function openProbeConnection(): \PDO
    {
        // `parse_url` requires a host after `://`, but `sqlite:///:memory:` and
        // `sqlite:///tmp/db.sqlite` are common DSN forms without one. Patch the
        // host in when it's missing so parse_url accepts the URL (mirrors the
        // same trick doctrine/dbal's DsnParser uses).
        $url = preg_replace('#^((?:pdo-)?sqlite3?):///#', '$1://localhost/', $this->databaseUrl);
        assert($url !== null);

        /** @var array{scheme?: string, host?: string, port?: int, user?: string, pass?: string, path?: string}|false $parts */
        $parts = parse_url($url);
        if ($parts === false) {
            throw new \RuntimeException('Malformed DATABASE_URL');
        }

        $scheme = $parts['scheme'] ?? 'mysql';
        $driver = match ($scheme) {
            'pgsql', 'postgres', 'postgresql' => 'pgsql',
            'sqlite', 'sqlite3' => 'sqlite',
            default => 'mysql',
        };

        if ($driver === 'sqlite') {
            $dbname = ltrim($parts['path'] ?? '/:memory:', '/');
            return new \PDO("sqlite:{$dbname}", options: [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
        }

        $host = $parts['host'] ?? 'localhost';
        $port = $parts['port'] ?? 3306;
        $user = $parts['user'] ?? 'root';
        $pass = $parts['pass'] ?? '';
        $dbname = ltrim($parts['path'] ?? '/webcalendar', '/');

        $dsn = "{$driver}:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
        $pdo = new \PDO($dsn, $user, $pass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        if ($driver === 'mysql') {
            // MAX_EXECUTION_TIME is a session-level hint in ms (SELECT only).
            // No-op on older servers / MariaDB, which is fine — the connect
            // timeout above still bounds the worst case.
            $pdo->exec('SET SESSION MAX_EXECUTION_TIME=' . self::QUERY_MAX_EXECUTION_TIME_MS);
        }

        return $pdo;
    }
}
