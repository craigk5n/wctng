<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenant;

use App\Tenant\ControlPlaneWebhook;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ControlPlaneWebhookTest extends TestCase
{
    public function testSkipsWhenUrlIsEmpty(): void
    {
        $webhook = new ControlPlaneWebhook('', new NullLogger());

        // Should not throw — just silently skips
        $webhook->tenantProvisioned('acme', 'Acme Corp');
        $webhook->tenantSuspended('acme');
        $webhook->tenantActivated('acme');
        $webhook->tenantDeleted('acme');

        // If we got here without exception, the test passes
        $this->assertTrue(true);
    }

    public function testSendToUnreachableUrlDoesNotThrow(): void
    {
        // Use a URL that will fail to connect
        $webhook = new ControlPlaneWebhook('http://127.0.0.1:19999/webhook', new NullLogger());

        // Should not throw — fire-and-forget
        $webhook->tenantProvisioned('test-co', 'Test Company');

        $this->assertTrue(true);
    }

    public function testSendToInvalidUrlDoesNotThrow(): void
    {
        $webhook = new ControlPlaneWebhook('not-a-url', new NullLogger());

        $webhook->tenantDeleted('bad-co');

        $this->assertTrue(true);
    }
}
