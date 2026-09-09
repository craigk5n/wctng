<?php

declare(strict_types=1);

namespace App\Tenant;

/**
 * Sends one control-plane notification.
 *
 * Split out of ControlPlaneWebhook for the same reason WebhookTransport was
 * split out of WebhookDispatcher: with curl welded in, nothing could observe
 * what was sent or what the response did, so the event names, the payload and
 * the error-logging decision were all unassertable.
 *
 * Deliberately not the same interface as App\Webhook\WebhookTransport. That
 * one signs every request and validates the target against the SSRF rules,
 * which is right for a tenant-supplied webhook URL and wrong here: this URL
 * comes from CONTROL_WEBHOOK_URL in the operator's own environment, is not
 * signed, and pointing it at an internal address is a normal deployment
 * rather than an attack.
 */
interface ControlPlaneTransport
{
    /**
     * @return int the HTTP status code, or 0 when no response was obtained
     */
    public function post(string $url, string $payload): int;
}
