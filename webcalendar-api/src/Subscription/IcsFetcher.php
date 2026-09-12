<?php

declare(strict_types=1);

namespace App\Subscription;

/**
 * Retrieves a subscribed calendar's ICS feed.
 *
 * A seam over curl, for the same reason WebhookTransport and
 * OidcConfigFetcher are ones: the request is the whole method, so with it
 * welded into the controller no test could reach the parsing, the conditional
 * GET, or the failure path at all.
 *
 * The seam also puts every subscription fetch behind one outbound check. The
 * URL is stored by whoever created the subscription -- any authenticated user
 * -- and the request then leaves from inside the deployment's network.
 */
interface IcsFetcher
{
    /**
     * @param string|null $etag the ETag stored from the last fetch, sent as
     *   If-None-Match so an unchanged feed costs a 304
     *
     * @return array{body: string, etag: string|null}|null null when the feed
     *   has not changed or the request failed
     *
     * @throws \InvalidArgumentException if the target fails the outbound checks
     */
    public function fetch(string $url, ?string $etag): ?array;
}
