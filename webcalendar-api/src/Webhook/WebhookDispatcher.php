<?php

declare(strict_types=1);

namespace App\Webhook;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Dispatches webhook payloads to subscribed endpoints.
 *
 * Fire-and-forget with retry on failure (3 attempts, exponential backoff).
 * Logs delivery results for audit trail.
 */
final class WebhookDispatcher
{
    private const MAX_RETRIES = 3;
    private const RETRY_DELAYS = [1, 5, 30]; // seconds

    private LoggerInterface $logger;

    public function __construct(
        private readonly WebhookRepository $repository,
        private readonly \PDO $pdo,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
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
            'timestamp' => (new \DateTimeImmutable())->format('c'),
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
                sleep(self::RETRY_DELAYS[$attempt]);
            }
        }
    }

    private function deliver(string $url, string $payload, string $signature): int
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return 0;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                "X-Webhook-Signature: sha256={$signature}",
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode;
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
                'at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);

            // Keep only last 100 per webhook
            $this->pdo->prepare(
                'DELETE FROM webhook_delivery_log WHERE webhook_id = :wid AND rowid NOT IN
                 (SELECT rowid FROM webhook_delivery_log WHERE webhook_id = :wid2 ORDER BY delivered_at DESC LIMIT 100)',
            )->execute(['wid' => $webhookId, 'wid2' => $webhookId]);
        } catch (\Throwable) {
            // Non-fatal
        }
    }

    private function ensureLogTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS webhook_delivery_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                webhook_id INTEGER NOT NULL,
                status_code INTEGER NOT NULL DEFAULT 0,
                response_body TEXT NOT NULL DEFAULT \'\',
                delivered_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )',
        );
    }
}
