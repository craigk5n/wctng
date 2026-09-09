<?php

declare(strict_types=1);

namespace App\Auth;

use App\Security\OutboundUrlValidator;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

/**
 * OpenID Connect discovery and ID token validation.
 *
 * Fetches provider configuration from .well-known/openid-configuration
 * and validates ID tokens using the provider's JWKS.
 */
final class OidcDiscovery
{
    /** @var array<string, array<string, mixed>> */
    private array $configCache = [];

    public function __construct(
        private readonly OutboundUrlValidator $urlValidator,
        private readonly ClockInterface $clock = new NativeClock(),
    ) {}

    /**
     * Discovers OIDC configuration from the provider's issuer URL.
     *
     * @return array<string, mixed>|null
     */
    public function discover(string $issuerUrl): ?array
    {
        if (isset($this->configCache[$issuerUrl])) {
            return $this->configCache[$issuerUrl];
        }

        $wellKnownUrl = rtrim($issuerUrl, '/') . '/.well-known/openid-configuration';

        // The issuer is stored through the admin auth-provider API, so it is
        // attacker-reachable input in hosted mode, not operator configuration.
        try {
            $target = $this->urlValidator->validate($wellKnownUrl);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $ch = curl_init($wellKnownUrl);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ] + OutboundUrlValidator::curlSecurityOptions($target));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!\is_string($response) || $httpCode !== 200) {
            return null;
        }

        $config = json_decode($response, true);
        if (!\is_array($config)) {
            return null;
        }

        /** @var array<string, mixed> $config */
        $this->configCache[$issuerUrl] = $config;

        return $config;
    }

    /**
     * Extracts key OIDC endpoints from discovered configuration.
     *
     * @param array<string, mixed> $config
     *
     * @return array{auth_url: string, token_url: string, userinfo_url: string, jwks_uri: string, issuer: string}
     */
    public function extractEndpoints(array $config): array
    {
        return [
            'auth_url' => \is_string($config['authorization_endpoint'] ?? null) ? $config['authorization_endpoint'] : '',
            'token_url' => \is_string($config['token_endpoint'] ?? null) ? $config['token_endpoint'] : '',
            'userinfo_url' => \is_string($config['userinfo_endpoint'] ?? null) ? $config['userinfo_endpoint'] : '',
            'jwks_uri' => \is_string($config['jwks_uri'] ?? null) ? $config['jwks_uri'] : '',
            'issuer' => \is_string($config['issuer'] ?? null) ? $config['issuer'] : '',
        ];
    }

    /**
     * Validates an ID token's basic claims (without cryptographic signature verification).
     *
     * For production, signature verification against JWKS should be added.
     *
     * @return array<string, mixed>|null Decoded claims on success, null on failure
     */
    public function validateIdToken(string $idToken, string $expectedIssuer, string $expectedAudience): ?array
    {
        $parts = explode('.', $idToken);
        if (\count($parts) !== 3) {
            return null;
        }

        $payloadJson = base64_decode(strtr($parts[1], '-_', '+/'), true);
        if ($payloadJson === false) {
            return null;
        }

        $claims = json_decode($payloadJson, true);
        if (!\is_array($claims)) {
            return null;
        }

        /** @var array<string, mixed> $claims */

        // Validate issuer
        $iss = \is_string($claims['iss'] ?? null) ? $claims['iss'] : '';
        if ($expectedIssuer !== '' && $iss !== $expectedIssuer) {
            return null;
        }

        // Validate audience
        $aud = $claims['aud'] ?? null;
        if (\is_string($aud)) {
            if ($expectedAudience !== '' && $aud !== $expectedAudience) {
                return null;
            }
        } elseif (\is_array($aud)) {
            if ($expectedAudience !== '' && !\in_array($expectedAudience, $aud, true)) {
                return null;
            }
        }

        // Validate expiry
        $exp = \is_numeric($claims['exp'] ?? null) ? (int) $claims['exp'] : 0;
        if ($exp > 0 && $exp < $this->clock->now()->getTimestamp()) {
            return null;
        }

        return $claims;
    }

    /**
     * Auto-configures an OAuthProvider from an OIDC issuer URL.
     */
    public function autoConfigureProvider(string $name, string $issuerUrl, string $clientId, #[\SensitiveParameter] string $clientSecret): ?OAuthProvider
    {
        $config = $this->discover($issuerUrl);
        if ($config === null) {
            return null;
        }

        $endpoints = $this->extractEndpoints($config);

        return new OAuthProvider(
            id: 0,
            name: $name,
            type: 'oidc',
            clientId: $clientId,
            clientSecret: $clientSecret,
            authUrl: $endpoints['auth_url'],
            tokenUrl: $endpoints['token_url'],
            userinfoUrl: $endpoints['userinfo_url'],
            scopes: 'openid email profile',
            enabled: true,
        );
    }
}
