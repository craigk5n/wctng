<?php

declare(strict_types=1);

namespace App\Webhook;

/**
 * Guards outbound webhook targets against SSRF.
 *
 * Webhook URLs come from an administrator, which is not the same as coming
 * from the operator: in hosted mode a tenant administrator can register one,
 * and the request then leaves from inside the platform's network, where the
 * cloud metadata endpoint and internal services are reachable.
 *
 * Standalone deployments legitimately point webhooks at internal hosts, so the
 * network checks follow the same appMode split `ControlPlaneGuard` and
 * `TenantResolverListener` already use. Scheme and credential checks apply in
 * both modes -- no deployment has a reason to let a webhook target
 * `file://` or `gopher://`.
 */
final readonly class WebhookUrlValidator
{
    private const ALLOWED_SCHEMES = ['http', 'https'];

    /**
     * RFC 6598 shared address space. filter_var() does not consider it private
     * or reserved, but it is carrier NAT space and some clouds serve their
     * metadata endpoint from it, so it is rejected explicitly.
     */
    private const SHARED_ADDRESS_SPACE_START = '100.64.0.0';
    private const SHARED_ADDRESS_SPACE_END = '100.127.255.255';

    public function __construct(private string $appMode) {}

    /**
     * Standalone deployments skip the network checks; see the class docblock.
     */
    public function enforcesNetworkRules(): bool
    {
        return $this->appMode !== 'standalone';
    }

    /**
     * @return array{host: string, port: int, ip: string|null} `ip` is the
     *   address the request must be pinned to, or null when network rules are
     *   not enforced and no pinning applies.
     *
     * @throws \InvalidArgumentException when the URL must not be requested
     */
    public function validate(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['scheme']) || !isset($parts['host']) || $parts['host'] === '') {
            throw new \InvalidArgumentException('Webhook URL must be an absolute http(s) URL.');
        }

        $scheme = strtolower($parts['scheme']);

        if (!\in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            throw new \InvalidArgumentException(
                sprintf('Webhook URL scheme "%s" is not allowed; use http or https.', $scheme)
            );
        }

        // user:pass@host is a classic way to make a URL read as one host to a
        // human reviewer and resolve as another.
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('Webhook URL must not embed credentials.');
        }

        $host = $parts['host'];
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        if (!$this->enforcesNetworkRules()) {
            return ['host' => $host, 'port' => $port, 'ip' => null];
        }

        $addresses = $this->resolve($host);

        if ($addresses === []) {
            // Fail closed: an unresolvable host cannot be shown to be safe.
            throw new \InvalidArgumentException(sprintf('Webhook host "%s" does not resolve.', $host));
        }

        foreach ($addresses as $address) {
            if (!self::isPublicAddress($address)) {
                throw new \InvalidArgumentException(sprintf(
                    'Webhook host "%s" resolves to a private, reserved or link-local address (%s).',
                    $host,
                    $address,
                ));
            }
        }

        return ['host' => $host, 'port' => $port, 'ip' => $addresses[0]];
    }

    /**
     * Every address the host answers with, so a round-robin record cannot hide
     * an internal address behind a public one.
     *
     * @return list<string>
     */
    private function resolve(string $host): array
    {
        // Bracketed IPv6 literal, e.g. http://[::1]:8080/
        $literal = trim($host, '[]');

        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            return [$literal];
        }

        $addresses = [];

        $v4 = gethostbynamel($host);

        if (\is_array($v4)) {
            foreach ($v4 as $address) {
                $addresses[] = $address;
            }
        }

        $v6 = @dns_get_record($host, \DNS_AAAA);

        if (\is_array($v6)) {
            foreach ($v6 as $record) {
                if (isset($record['ipv6']) && \is_string($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($addresses));
    }

    private static function isPublicAddress(string $address): bool
    {
        $public = filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        );

        if ($public === false) {
            return false;
        }

        $long = ip2long($address);

        if ($long === false) {
            return true; // IPv6, already covered by the flags above.
        }

        return $long < ip2long(self::SHARED_ADDRESS_SPACE_START)
            || $long > ip2long(self::SHARED_ADDRESS_SPACE_END);
    }
}
