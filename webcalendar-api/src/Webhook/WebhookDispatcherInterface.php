<?php

declare(strict_types=1);

namespace App\Webhook;

/**
 * Narrow contract for dispatching webhook events.
 *
 * Extracted so services that only need to emit (not manage) webhooks can
 * depend on the interface and be tested with a lightweight double, rather
 * than instantiating the full `final` WebhookDispatcher.
 */
interface WebhookDispatcherInterface
{
    /**
     * @param array<string, mixed> $data
     */
    public function dispatch(string $eventType, array $data): void;
}
