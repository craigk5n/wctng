<?php

declare(strict_types=1);

namespace App\Tenant;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;

/**
 * Sends webhook notifications for tenant lifecycle events.
 *
 * Fire-and-forget: webhook failures are logged but never block operations.
 */
final class ControlPlaneWebhook
{
    private LoggerInterface $logger;
    private ClockInterface $clock;

    public function __construct(
        private readonly string $webhookUrl,
        ?LoggerInterface $logger = null,
        ?ClockInterface $clock = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->clock = $clock ?? new NativeClock();
    }

    public function tenantProvisioned(string $slug, string $name): void
    {
        $this->send('tenant.provisioned', ['slug' => $slug, 'name' => $name]);
    }

    public function tenantSuspended(string $slug): void
    {
        $this->send('tenant.suspended', ['slug' => $slug]);
    }

    public function tenantActivated(string $slug): void
    {
        $this->send('tenant.activated', ['slug' => $slug]);
    }

    public function tenantDeleted(string $slug): void
    {
        $this->send('tenant.deleted', ['slug' => $slug]);
    }

    /**
     * @param array<string, string> $data
     */
    private function send(string $eventType, array $data): void
    {
        if ($this->webhookUrl === '') {
            return;
        }

        $payload = json_encode([
            'event' => $eventType,
            'timestamp' => $this->clock->now()->format('c'),
            ...$data,
        ], JSON_THROW_ON_ERROR);

        try {
            $ch = curl_init($this->webhookUrl);
            if ($ch === false) {
                $this->logger->warning('Failed to initialize cURL for webhook', ['url' => $this->webhookUrl]);
                return;
            }

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
            ]);

            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode >= 400) {
                $this->logger->warning('Webhook returned error', ['event' => $eventType, 'http_code' => $httpCode]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Webhook failed', ['event' => $eventType, 'error' => $e->getMessage()]);
        }
    }
}
