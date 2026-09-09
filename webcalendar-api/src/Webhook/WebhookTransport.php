<?php

declare(strict_types=1);

namespace App\Webhook;

/**
 * Sends one webhook request.
 *
 * Split out of WebhookDispatcher so the retry loop around it can be tested:
 * with curl welded in, no assertion could reach the 2xx branch, and the whole
 * success-versus-retry decision was unobservable.
 */
interface WebhookTransport
{
    /**
     * @return int the HTTP status code, or 0 when no response was obtained
     *
     * @throws \InvalidArgumentException when the target must not be requested
     *   at all. Distinct from a 0 return on purpose: a refused connection and
     *   a target that failed the SSRF checks are different events, and the
     *   caller logs them differently.
     */
    public function post(string $url, string $payload, string $signature): int;
}
