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
 * - Shares {@see DatabaseDsn} with PdoFactory for taking the URL apart, but
 *   not the connection: overriding the shared PDO's timeout would taint every
 *   later query on it.
 *
 * This is a ~2KB service lifted out of `HealthController` so the probe
 * stays testable in isolation.
 */
final class ReadinessProbe
{
    private const CONNECT_TIMEOUT_SECONDS = 1;
    private const QUERY_MAX_EXECUTION_TIME_MS = 100;

    private readonly ProbeConnector $connector;

    public function __construct(
        #[\SensitiveParameter]
        private readonly string $databaseUrl,
        ?ProbeConnector $connector = null,
    ) {
        $this->connector = $connector ?? new PdoProbeConnector();
    }

    public function pingDatabase(): DbProbeResult
    {
        $start = microtime(true);

        try {
            $pdo = $this->openProbeConnection();
            $pdo->query('SELECT 1');
        } catch (\Throwable) {
            return new DbProbeResult(ok: false, latencyMs: null);
        }

        // Mutation testing leaves four survivors on this line -- the factor
        // off by one either way, and round() swapped for floor() or ceil().
        // All four move a diagnostic number by at most a millisecond, and the
        // only test that could tell them apart would assert an exact duration,
        // which is a flakier thing than the mutants are a bug.
        $latencyMs = (int) round((microtime(true) - $start) * 1000);

        return new DbProbeResult(ok: true, latencyMs: $latencyMs);
    }

    private function openProbeConnection(): \PDO
    {
        $config = DatabaseDsn::fromUrl($this->databaseUrl);

        if ($config->driver === 'sqlite') {
            // No credentials, and no connect timeout to set: opening a file
            // does not block on a network.
            return $this->connector->connect($config->dsn, '', '', [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
        }

        $pdo = $this->connector->connect($config->dsn, $config->user, $config->password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        if ($config->driver === 'mysql') {
            // MAX_EXECUTION_TIME is a session-level hint in ms (SELECT only).
            // No-op on older servers / MariaDB, which is fine — the connect
            // timeout above still bounds the worst case.
            $pdo->exec('SET SESSION MAX_EXECUTION_TIME=' . self::QUERY_MAX_EXECUTION_TIME_MS);
        }

        return $pdo;
    }
}
