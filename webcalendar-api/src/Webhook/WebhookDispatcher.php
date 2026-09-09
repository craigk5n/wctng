<?php

declare(strict_types=1);

namespace App\Webhook;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;

/**
 * Dispatches webhook payloads to subscribed endpoints.
 *
 * Fire-and-forget with retry on failure (3 attempts, exponential backoff).
 * Logs delivery results for audit trail.
 */
final class WebhookDispatcher implements WebhookDispatcherInterface
{
    private const LOG_SCHEMA_SQL = <<<'SQL'
            CREATE TABLE IF NOT EXISTS webhook_delivery_log (
                id INTEGER PRIMARY KEY AUTO_INCREMENT,
                webhook_id INTEGER NOT NULL,
                status_code INTEGER NOT NULL DEFAULT 0,
                response_body TEXT NOT NULL,
                delivered_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        SQL;

    private const LOG_SCHEMA_SQL_SQLITE = <<<'SQL'
            CREATE TABLE IF NOT EXISTS webhook_delivery_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                webhook_id INTEGER NOT NULL,
                status_code INTEGER NOT NULL DEFAULT 0,
                response_body TEXT NOT NULL DEFAULT '',
                delivered_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        SQL;

    private const MAX_RETRIES = 3;
    private const RETRY_DELAYS = [1, 5, 30]; // seconds

    private LoggerInterface $logger;
    private ClockInterface $clock;

    /**
     * @param list<int> $retryDelays seconds to wait between attempts. Defaulted
     *   so nothing else has to pass it; tests hand in zeros, because otherwise
     *   asserting anything about the retry loop costs the real 1s + 5s backoff
     *   per case -- and Infection reruns those tests once per mutant.
     */
    public function __construct(
        private readonly WebhookRepository $repository,
        private readonly \PDO $pdo,
        private readonly WebhookTransport $transport,
        ?LoggerInterface $logger = null,
        ?ClockInterface $clock = null,
        private readonly array $retryDelays = self::RETRY_DELAYS,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->clock = $clock ?? new NativeClock();
    }

    /**
     * Dispatches an event to all subscribed webhooks.
     *
     * @param array<string, mixed> $data Event payload data
     */
    public function dispatch(string $eventType, array $data): void
    {
        $this->ensureLogTable();

        $payload = json_encode([
            'event' => $eventType,
            'timestamp' => $this->clock->now()->format('c'),
            'data' => $data,
        ], JSON_THROW_ON_ERROR);

        $webhooks = $this->repository->findEnabled();

        foreach ($webhooks as $webhook) {
            if (!$webhook->subscribesTo($eventType)) {
                continue;
            }

            $this->deliverWithRetry($webhook, $payload);
        }
    }

    /**
     * Returns recent delivery log entries for a webhook.
     *
     * @return list<array{webhook_id: int, status_code: int, response: string, delivered_at: string}>
     */
    public function getDeliveryLog(int $webhookId, int $limit = 100): array
    {
        $this->ensureLogTable();

        $stmt = $this->pdo->prepare(
            'SELECT webhook_id, status_code, response_body, delivered_at FROM webhook_delivery_log
             WHERE webhook_id = :id ORDER BY delivered_at DESC LIMIT :limit',
        );
        $stmt->bindValue('id', $webhookId, \PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $log = [];
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        while (\is_array($row)) {
            $log[] = [
                'webhook_id' => \is_numeric($row['webhook_id'] ?? null) ? (int) $row['webhook_id'] : 0,
                'status_code' => \is_numeric($row['status_code'] ?? null) ? (int) $row['status_code'] : 0,
                'response' => \is_string($row['response_body'] ?? null) ? $row['response_body'] : '',
                'delivered_at' => \is_string($row['delivered_at'] ?? null) ? $row['delivered_at'] : '',
            ];
            /** @var array<string, mixed>|false $row */
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        }

        return $log;
    }

    private function deliverWithRetry(WebhookSubscription $webhook, string $payload): void
    {
        $signature = hash_hmac('sha256', $payload, $webhook->secret());

        for ($attempt = 0; $attempt < self::MAX_RETRIES; $attempt++) {
            $statusCode = $this->deliver($webhook->url(), $payload, $signature);

            $this->logDelivery($webhook->id(), $statusCode, $attempt + 1);

            if ($statusCode >= 200 && $statusCode < 300) {
                $this->logger->info('Webhook delivered', [
                    'webhook_id' => $webhook->id(),
                    'url' => $webhook->url(),
                    'status' => $statusCode,
                ]);

                return;
            }

            $this->logger->warning('Webhook delivery failed', [
                'webhook_id' => $webhook->id(),
                'attempt' => $attempt + 1,
                'status' => $statusCode,
            ]);

            if ($attempt < self::MAX_RETRIES - 1) {
                sleep($this->retryDelays[$attempt] ?? 0);
            }
        }
    }

    private function deliver(string $url, string $payload, string $signature): int
    {
        try {
            return $this->transport->post($url, $payload, $signature);
        } catch (\InvalidArgumentException $e) {
            // A target that fails the SSRF checks is logged as blocked, not as
            // an ordinary failure, and still counts as a failed attempt so the
            // retry loop and delivery log behave the same either way.
            $this->logger->error('Webhook delivery blocked', [
                'url' => $url,
                'reason' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    private function logDelivery(int $webhookId, int $statusCode, int $attempt): void
    {
        try {
            $this->pdo->prepare(
                'INSERT INTO webhook_delivery_log (webhook_id, status_code, response_body, delivered_at)
                 VALUES (:wid, :status, :response, :at)',
            )->execute([
                'wid' => $webhookId,
                'status' => $statusCode,
                'response' => "attempt {$attempt}",
                'at' => $this->clock->now()->format('Y-m-d H:i:s'),
            ]);

            // Keep only last 100 per webhook. `rowid` is SQLite-only and MySQL
            // rejects LIMIT inside an IN subquery (error 1235), so this goes
            // through `id` and a derived table, which both engines accept.
            $this->pdo->prepare(
                'DELETE FROM webhook_delivery_log WHERE webhook_id = :wid AND id NOT IN
                 (SELECT id FROM (
                      SELECT id FROM webhook_delivery_log WHERE webhook_id = :wid2
                      ORDER BY delivered_at DESC LIMIT 100
                  ) AS keep)',
            )->execute(['wid' => $webhookId, 'wid2' => $webhookId]);
        } catch (\Throwable $e) {
            // Non-fatal, but not silent: a broken log table used to swallow
            // itself here, which is how the SQLite-only DDL above survived
            // unnoticed on MySQL.
            $this->logger->debug('Webhook delivery log write failed', [
                'webhook_id' => $webhookId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function ensureLogTable(): void
    {
        // Same AUTOINCREMENT/AUTO_INCREMENT split as the other repositories.
        // MySQL additionally rejects a DEFAULT on TEXT (error 1101), so
        // response_body carries none there; every insert supplies it.
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        $this->pdo->exec($driver === 'sqlite' ? self::LOG_SCHEMA_SQL_SQLITE : self::LOG_SCHEMA_SQL);
    }
}
