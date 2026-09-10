<?php

declare(strict_types=1);

namespace App\Auth;

use App\Security\OutboundUrlValidator;

/**
 * OidcConfigFetcher over curl.
 *
 * Validation lives here rather than in OidcDiscovery, matching
 * CurlWebhookTransport: the address that clears the checks is the one the
 * connection has to be pinned to, and that pin is a curl option.
 */
final readonly class CurlOidcConfigFetcher implements OidcConfigFetcher
{
    private const TIMEOUT_SECONDS = 10;

    public function __construct(private OutboundUrlValidator $urlValidator) {}

    #[\Override]
    public function fetch(string $url): ?string
    {
        // The issuer is stored through the admin auth-provider API, so it is
        // attacker-reachable input in hosted mode, not operator configuration.
        $target = $this->urlValidator->validate($url);

        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ] + OutboundUrlValidator::curlSecurityOptions($target));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!\is_string($response) || $httpCode !== 200) {
            return null;
        }

        return $response;
    }
}
