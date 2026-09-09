<?php

declare(strict_types=1);

namespace App\Tests\Unit\Webhook;

use App\Security\OutboundUrlValidator;
use App\Webhook\WebhookDispatcher;
use App\Webhook\WebhookRepository;
use App\Webhook\WebhookSubscription;
use PHPUnit\Framework\TestCase;

final class WebhookDispatcherTest extends TestCase
{
    private \PDO $pdo;
    private WebhookRepository $repo;
    private WebhookDispatcher $dispatcher;

    #[\Override]
    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->repo = new WebhookRepository($this->pdo);
        // standalone: these cases target 127.0.0.1 to exercise the unreachable
        // path, which hosted mode would refuse to dial at all.
        $this->dispatcher = new WebhookDispatcher(
            $this->repo,
            $this->pdo,
            new OutboundUrlValidator('standalone'),
            null,
            null,
            // Zero backoff: these cases all exhaust the retries, and the real
            // 1s + 5s wait is paid again for every mutant Infection runs here.
            retryDelays: [0, 0, 0],
        );
    }

    public function testDispatchWithNoWebhooksDoesNothing(): void
    {
        // Should not throw
        $this->dispatcher->dispatch('event.created', ['id' => 1, 'title' => 'Test']);
        $this->assertTrue(true);
    }

    public function testDispatchFiltersUnsubscribedEvents(): void
    {
        // Webhook only subscribes to event.deleted
        $this->repo->save(new WebhookSubscription(0, 'http://127.0.0.1:19999', 'event.deleted', 'secret', true));

        // Dispatching event.created should not try to deliver to this webhook
        $this->dispatcher->dispatch('event.created', ['id' => 1]);

        // Check delivery log — should be empty for this webhook
        $id = $this->repo->findAll()[0]->id();
        $log = $this->dispatcher->getDeliveryLog($id);
        $this->assertEmpty($log);
    }

    public function testDispatchToUnreachableUrlLogs(): void
    {
        $id = $this->repo->save(new WebhookSubscription(0, 'http://127.0.0.1:19999/nonexistent', '*', 'secret', true));

        // Will fail to connect — should log the failure
        $this->dispatcher->dispatch('event.created', ['id' => 1]);

        $log = $this->dispatcher->getDeliveryLog($id);
        $this->assertNotEmpty($log);
        // Status code 0 = connection failed
        $this->assertSame(0, $log[0]['status_code']);
    }

    public function testDeliveryLogReturnsEntries(): void
    {
        // Manually insert log entries
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS webhook_delivery_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                webhook_id INTEGER NOT NULL,
                status_code INTEGER NOT NULL DEFAULT 0,
                response_body TEXT NOT NULL DEFAULT \'\',
                delivered_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )',
        );
        $this->pdo->exec("INSERT INTO webhook_delivery_log (webhook_id, status_code, response_body, delivered_at) VALUES (42, 200, 'ok', '2026-03-17 12:00:00')");
        $this->pdo->exec("INSERT INTO webhook_delivery_log (webhook_id, status_code, response_body, delivered_at) VALUES (42, 500, 'error', '2026-03-17 12:01:00')");

        $log = $this->dispatcher->getDeliveryLog(42);
        $this->assertCount(2, $log);
        $this->assertSame(500, $log[0]['status_code']); // Most recent first
    }

    public function testHmacSignatureInHeader(): void
    {
        $payload = '{"event":"event.created","data":{}}';
        $secret = 'webhook-secret';
        $signature = hash_hmac('sha256', $payload, $secret);

        // Verify the signature format
        $this->assertSame(64, \strlen($signature));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $signature);
    }
    public function testHostedModeBlocksDeliveryToInternalAddress(): void
    {
        $dispatcher = new WebhookDispatcher(
            $this->repo,
            $this->pdo,
            new OutboundUrlValidator('hosted'),
        );

        $id = $this->repo->save(
            new WebhookSubscription(0, 'http://169.254.169.254/latest/meta-data/', '*', 'secret', true)
        );

        $dispatcher->dispatch('event.created', ['id' => 1]);

        // Logged as a failed delivery rather than silently dropped, and the
        // request is never dialled.
        $log = $dispatcher->getDeliveryLog($id);
        $this->assertNotEmpty($log);
        $this->assertSame(0, $log[0]['status_code']);
    }
}
