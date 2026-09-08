<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use App\Auth\OidcDiscovery;
use App\Security\OutboundUrlValidator;
use PHPUnit\Framework\TestCase;

final class OidcDiscoveryTest extends TestCase
{
    /**
     * standalone: these tests reach 127.0.0.1 to exercise the unreachable path,
     * which hosted mode would refuse to dial at all.
     */
    private function discovery(): OidcDiscovery
    {
        return new OidcDiscovery(new OutboundUrlValidator('standalone'));
    }

    public function testExtractEndpointsFromConfig(): void
    {
        $discovery = $this->discovery();

        $config = [
            'issuer' => 'https://accounts.google.com',
            'authorization_endpoint' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_endpoint' => 'https://oauth2.googleapis.com/token',
            'userinfo_endpoint' => 'https://openidconnect.googleapis.com/v1/userinfo',
            'jwks_uri' => 'https://www.googleapis.com/oauth2/v3/certs',
        ];

        $endpoints = $discovery->extractEndpoints($config);

        $this->assertSame('https://accounts.google.com/o/oauth2/v2/auth', $endpoints['auth_url']);
        $this->assertSame('https://oauth2.googleapis.com/token', $endpoints['token_url']);
        $this->assertSame('https://openidconnect.googleapis.com/v1/userinfo', $endpoints['userinfo_url']);
        $this->assertSame('https://www.googleapis.com/oauth2/v3/certs', $endpoints['jwks_uri']);
        $this->assertSame('https://accounts.google.com', $endpoints['issuer']);
    }

    public function testExtractEndpointsHandlesMissingFields(): void
    {
        $discovery = $this->discovery();

        $endpoints = $discovery->extractEndpoints([]);

        $this->assertSame('', $endpoints['auth_url']);
        $this->assertSame('', $endpoints['token_url']);
        $this->assertSame('', $endpoints['userinfo_url']);
    }

    public function testValidateIdTokenWithValidClaims(): void
    {
        $discovery = $this->discovery();

        // Create a mock JWT (header.payload.signature)
        $header = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = base64_encode(json_encode([
            'iss' => 'https://accounts.google.com',
            'aud' => 'my-client-id',
            'sub' => '1234567890',
            'email' => 'user@gmail.com',
            'name' => 'Test User',
            'exp' => time() + 3600,
        ], JSON_THROW_ON_ERROR));
        $signature = base64_encode('fake-signature');

        $idToken = "{$header}.{$payload}.{$signature}";

        $claims = $discovery->validateIdToken($idToken, 'https://accounts.google.com', 'my-client-id');

        $this->assertNotNull($claims);
        $this->assertSame('user@gmail.com', $claims['email']);
        $this->assertSame('Test User', $claims['name']);
    }

    public function testValidateIdTokenRejectsExpired(): void
    {
        $discovery = $this->discovery();

        $header = base64_encode(json_encode(['alg' => 'RS256'], JSON_THROW_ON_ERROR));
        $payload = base64_encode(json_encode([
            'iss' => 'https://example.com',
            'aud' => 'client',
            'exp' => time() - 3600, // Expired
        ], JSON_THROW_ON_ERROR));
        $signature = base64_encode('sig');

        $claims = $discovery->validateIdToken("{$header}.{$payload}.{$signature}", 'https://example.com', 'client');

        $this->assertNull($claims);
    }

    public function testValidateIdTokenRejectsWrongIssuer(): void
    {
        $discovery = $this->discovery();

        $header = base64_encode(json_encode(['alg' => 'RS256'], JSON_THROW_ON_ERROR));
        $payload = base64_encode(json_encode([
            'iss' => 'https://evil.com',
            'aud' => 'client',
            'exp' => time() + 3600,
        ], JSON_THROW_ON_ERROR));
        $signature = base64_encode('sig');

        $claims = $discovery->validateIdToken("{$header}.{$payload}.{$signature}", 'https://expected.com', 'client');

        $this->assertNull($claims);
    }

    public function testValidateIdTokenRejectsWrongAudience(): void
    {
        $discovery = $this->discovery();

        $header = base64_encode(json_encode(['alg' => 'RS256'], JSON_THROW_ON_ERROR));
        $payload = base64_encode(json_encode([
            'iss' => 'https://example.com',
            'aud' => 'wrong-client',
            'exp' => time() + 3600,
        ], JSON_THROW_ON_ERROR));
        $signature = base64_encode('sig');

        $claims = $discovery->validateIdToken("{$header}.{$payload}.{$signature}", 'https://example.com', 'my-client');

        $this->assertNull($claims);
    }

    public function testValidateIdTokenRejectsInvalidFormat(): void
    {
        $discovery = $this->discovery();

        $this->assertNull($discovery->validateIdToken('not-a-jwt', '', ''));
        $this->assertNull($discovery->validateIdToken('a.b', '', ''));
    }

    public function testValidateIdTokenAcceptsArrayAudience(): void
    {
        $discovery = $this->discovery();

        $header = base64_encode(json_encode(['alg' => 'RS256'], JSON_THROW_ON_ERROR));
        $payload = base64_encode(json_encode([
            'iss' => 'https://example.com',
            'aud' => ['client-a', 'client-b'],
            'exp' => time() + 3600,
        ], JSON_THROW_ON_ERROR));
        $signature = base64_encode('sig');

        $claims = $discovery->validateIdToken("{$header}.{$payload}.{$signature}", 'https://example.com', 'client-b');
        $this->assertNotNull($claims);
    }

    public function testDiscoverReturnsNullForUnreachableUrl(): void
    {
        $discovery = $this->discovery();

        $result = $discovery->discover('http://127.0.0.1:19999');
        $this->assertNull($result);
    }
    public function testHostedModeRefusesInternalIssuer(): void
    {
        // The issuer is admin-supplied through the auth-provider API, so in
        // hosted mode discovery must not be usable to probe the network. This
        // is refused before curl is dialled at all.
        $discovery = new OidcDiscovery(new OutboundUrlValidator('hosted'));

        $this->assertNull($discovery->discover('http://169.254.169.254'));
        $this->assertNull($discovery->discover('file:///etc/passwd'));
    }
}
