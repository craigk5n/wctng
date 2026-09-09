<?php

declare(strict_types=1);

namespace App\Webhook;

use App\Security\OutboundUrlValidator;

/**
 * WebhookTransport over curl.
 *
 * Validation lives here rather than in the dispatcher because it is
 * inseparable from the request: the address that clears the checks is the one
 * the connection has to be pinned to, and that pin is a curl option.
 */
final readonly class CurlWebhookTransport implements WebhookTransport
{
    private const TIMEOUT_SECONDS = 10;
    private const CONNECT_TIMEOUT_SECONDS = 5;

    public function __construct(private OutboundUrlValidator $urlValidator) {}

    #[\Override]
    public function post(string $url, string $payload, string $signature): int
    {
        // Re-checked on every delivery, not just at registration: the record
        // outlives the check, and the name can start answering with an
        // internal address at any point after it was stored.
        $target = $this->urlValidator->validate($url);

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
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
        ] + OutboundUrlValidator::curlSecurityOptions($target));

        curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode;
    }
}
