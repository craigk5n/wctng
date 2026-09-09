<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

/**
 * Lightweight error counter stored in a `system_metrics` table.
 * Used by ExceptionSubscriber to track 5xx errors and by HealthController to report them.
 */
final class ErrorMetricsService
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly ClockInterface $clock = new NativeClock(),
    ) {
        $this->ensureTable();
    }

    /**
     * Increment the error counter for today.
     */
    public function recordError(): void
    {
        $now = $this->clock->now();
        $today = $now->format('Y-m-d');
        $key = "errors_{$today}";

        // Upsert: increment if exists, insert if not
        $stmt = $this->pdo->prepare(
            'UPDATE system_metrics SET metric_value = metric_value + 1, updated_at = :now WHERE metric_key = :key',
        );
        $stmt->execute(['key' => $key, 'now' => $now->getTimestamp()]);

        if ($stmt->rowCount() === 0) {
            $this->pdo->prepare(
                'INSERT INTO system_metrics (metric_key, metric_value, updated_at) VALUES (:key, 1, :now)',
            )->execute(['key' => $key, 'now' => $now->getTimestamp()]);
        }
    }

    /**
     * Get the total error count for the last N days.
     */
    public function getRecentErrorCount(int $days = 1): int
    {
        $keys = [];
        $params = [];
        $now = $this->clock->now();
        for ($i = 0; $i < $days; $i++) {
            $date = $now->modify("-{$i} days")->format('Y-m-d');
            $key = "k{$i}";
            $keys[] = ":{$key}";
            $params[$key] = "errors_{$date}";
        }

        $sql = 'SELECT COALESCE(SUM(metric_value), 0) FROM system_metrics WHERE metric_key IN (' . implode(', ', $keys) . ')';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        /** @var numeric-string|false $result */
        $result = $stmt->fetchColumn();

        return $result !== false ? (int) $result : 0;
    }

    /**
     * Clean up old metrics (older than 30 days).
     */
    public function cleanup(): void
    {
        $cutoff = $this->clock->now()->modify('-30 days')->format('Y-m-d');
        $this->pdo->prepare('DELETE FROM system_metrics WHERE metric_key < :cutoff AND metric_key LIKE :prefix')
            ->execute(['cutoff' => "errors_{$cutoff}", 'prefix' => 'errors_%']);
    }

    private function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS system_metrics (
                metric_key VARCHAR(100) NOT NULL PRIMARY KEY,
                metric_value INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL
            )',
        );
    }
}
