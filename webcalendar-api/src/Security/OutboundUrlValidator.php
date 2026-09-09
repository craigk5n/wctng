<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Guards server-initiated outbound requests against SSRF.
 *
 * Every target this covers -- webhook subscriptions, OAuth token and userinfo
 * endpoints, OIDC issuers -- is stored through an admin API at runtime, which
 * is not the same as coming from the operator: in hosted mode a tenant
 * administrator can write one, and the request then leaves from inside the
 * platform's network, where the cloud metadata endpoint and internal services
 * are reachable. The OAuth endpoints raise the stakes further, because the
 * request carries the client secret or an access token.
 *
 * Standalone deployments legitimately point webhooks at internal hosts, so the
 * network checks follow the same appMode split `ControlPlaneGuard` and
 * `TenantResolverListener` already use. Scheme and credential checks apply in
 * both modes -- no deployment has a reason to let a webhook target
 * `file://` or `gopher://`.
 */
final readonly class OutboundUrlValidator
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
            throw new \InvalidArgumentException('URL must be absolute and use http or https.');
        }

        $scheme = strtolower($parts['scheme']);

        if (!\in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            throw new \InvalidArgumentException(
                sprintf('URL scheme "%s" is not allowed; use http or https.', $scheme)
            );
        }

        // user:pass@host is a classic way to make a URL read as one host to a
        // human reviewer and resolve as another.
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('URL must not embed credentials.');
        }

        $host = $parts['host'];
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        if (!$this->enforcesNetworkRules()) {
            return ['host' => $host, 'port' => $port, 'ip' => null];
        }

        $addresses = $this->resolve($host);

        if ($addresses === []) {
            // Fail closed: an unresolvable host cannot be shown to be safe.
            throw new \InvalidArgumentException(sprintf('Host "%s" does not resolve.', $host));
        }

        foreach ($addresses as $address) {
            if (!self::isPublicAddress($address)) {
                throw new \InvalidArgumentException(sprintf(
                    'Host "%s" resolves to a private, reserved or link-local address (%s).',
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
     * The AAAA half of this is not mutation-covered: reaching it needs a host
     * with live DNS records, and dns_get_record() bypasses /etc/hosts, so
     * there is no hermetic way in. Deliberately left rather than papered over
     * with a resolver abstraction -- each surviving mutant there degrades to
     * collecting no addresses, which fails closed on the empty check below
     * rather than admitting an address that was never validated.
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
            // IPv6, already covered by the flags above. Infection reports
            // removing this return as a surviving mutant; it is equivalent.
            // Falling through compares false against the range bounds, and
            // PHP evaluates that as bool, yielding true either way.
            return true;
        }

        return $long < ip2long(self::SHARED_ADDRESS_SPACE_START)
            || $long > ip2long(self::SHARED_ADDRESS_SPACE_END);
    }

    /**
     * curl options that keep a validated request on the path it was checked on.
     *
     * @param array{host: string, port: int, ip: string|null} $target as returned by validate()
     *
     * @return array<int, mixed>
     */
    public static function curlSecurityOptions(array $target): array
    {
        $options = [
            // curl speaks far more than HTTP; without this a stored target could
            // name file:// or gopher://. Redirects stay off so a 302 cannot walk
            // the request somewhere the checks never saw.
            CURLOPT_PROTOCOLS_STR => 'http,https',
            CURLOPT_REDIR_PROTOCOLS_STR => 'http,https',
            CURLOPT_FOLLOWLOCATION => false,
        ];

        if ($target['ip'] !== null) {
            // Pin to the address just validated, so a second DNS answer between
            // the check and the connect cannot redirect this request inside the
            // network.
            $options[CURLOPT_RESOLVE] = [
                sprintf('%s:%d:%s', $target['host'], $target['port'], $target['ip']),
            ];
        }

        return $options;
    }
}
