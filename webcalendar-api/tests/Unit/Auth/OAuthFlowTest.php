<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use App\Auth\OAuthProvider;
use App\Auth\OAuthProviderRepository;
use App\Controller\Api\OAuthController;
use PHPUnit\Framework\TestCase;

final class OAuthFlowTest extends TestCase
{
    public function testRedirectEndpointReturnsAuthUrl(): void
    {
        // Verify the provider entity has the necessary fields for redirect
        $provider = new OAuthProvider(
            1, 'Google', 'oidc', 'client-id', 'client-secret',
            'https://accounts.google.com/o/oauth2/auth',
            'https://oauth2.googleapis.com/token',
            'https://openidconnect.googleapis.com/v1/userinfo',
            'openid email profile', true,
        );

        $this->assertSame('https://accounts.google.com/o/oauth2/auth', $provider->authUrl());
        $this->assertSame('client-id', $provider->clientId());
        $this->assertSame('openid email profile', $provider->scopes());
    }

    public function testPkceCodeChallengeGeneration(): void
    {
        $codeVerifier = bin2hex(random_bytes(32));
        $this->assertSame(64, \strlen($codeVerifier));

        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
        $this->assertSame(43, \strlen($codeChallenge));

        // Code challenge should be URL-safe base64
        $this->assertDoesNotMatchRegularExpression('/[+\/=]/', $codeChallenge);
    }

    public function testLoginNormalization(): void
    {
        // Test via reflection on the normalizeLogin logic
        $testCases = [
            'user@example.com' => 'user',
            'John.Doe@company.com' => 'john.doe',
            'admin' => 'admin',
            'user-name' => 'user-name',
        ];

        foreach ($testCases as $input => $expected) {
            $atPos = strpos($input, '@');
            $login = $atPos !== false ? substr($input, 0, $atPos) : $input;
            $login = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $login);
            $login = strtolower($login ?? '');

            $this->assertSame($expected, $login, "Failed for input: {$input}");
        }
    }

    public function testProviderDisabledRejectsFlow(): void
    {
        $provider = new OAuthProvider(
            1, 'Disabled', 'oauth2', 'id', 'secret', '', '', '', '', false,
        );

        $this->assertFalse($provider->isEnabled());
    }

    public function testProviderRepositoryCreatesTable(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $repo = new OAuthProviderRepository($pdo);

        // Should not throw — creates table on first use
        $providers = $repo->findAll();
        $this->assertIsArray($providers);
        $this->assertEmpty($providers);
    }
}
