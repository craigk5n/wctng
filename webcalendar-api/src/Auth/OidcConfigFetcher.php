<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Retrieves an OIDC provider's discovery document.
 *
 * A seam over curl, for the same reason WebhookTransport is one: with the
 * request welded into OidcDiscovery, no test could reach the success path at
 * all. Everything discovery decides -- which URL it asks for, what it does
 * with a non-200, a body that is not JSON, or a body that is JSON but not an
 * object, and whether it asks twice for the same issuer -- sat behind a call
 * that needs a real provider on the other end.
 */
interface OidcConfigFetcher
{
    /**
     * @return string|null the response body on a 200, null on anything else
     *
     * @throws \InvalidArgumentException if the target fails the outbound checks
     */
    public function fetch(string $url): ?string;
}
