<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AuthLogoutRefreshTest extends WebTestCase
{
    private function loginAsAdmin(?KernelBrowser $client = null): string
    {
        $client ??= static::createClient();
        $client->request('POST', '/api/v2/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], (string) json_encode(['username' => 'admin', 'password' => 'admin']));

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);

        /** @var array{data: array{token: string}} $body */
        $body = json_decode($content, true);
        self::assertIsArray($body);

        return $body['data']['token'];
    }

    // --- Refresh ---

    public function testRefreshReturnsNewToken(): void
    {
        $client = static::createClient();
        $token = $this->loginAsAdmin($client);

        $client->request('POST', '/api/v2/auth/refresh', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        $this->assertIsString($content);

        /** @var array{data: array{token: string, expires_at: string}} $body */
        $body = json_decode($content, true);
        $this->assertIsArray($body);

        $this->assertArrayHasKey('token', $body['data']);
        $this->assertNotEmpty($body['data']['token']);
        $this->assertArrayHasKey('expires_at', $body['data']);
        // Token should be a valid JWT
        $parts = explode('.', $body['data']['token']);
        $this->assertCount(3, $parts);
    }

    public function testRefreshReturnsJsonContentType(): void
    {
        $client = static::createClient();
        $token = $this->loginAsAdmin($client);

        $client->request('POST', '/api/v2/auth/refresh', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseHeaderSame('Content-Type', 'application/json');
    }

    public function testRefreshWithoutTokenFails(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/v2/auth/refresh');

        // Symfony returns 401 or 403 depending on firewall config
        $code = $client->getResponse()->getStatusCode();
        $this->assertTrue(\in_array($code, [401, 403], true), "Expected 401 or 403, got {$code}");
    }

    public function testRefreshWithInvalidTokenFails(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/v2/auth/refresh', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer invalid.token.here',
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    // --- Logout ---

    public function testLogoutReturns204(): void
    {
        $client = static::createClient();
        $token = $this->loginAsAdmin($client);

        $client->request('POST', '/api/v2/auth/logout', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseStatusCodeSame(204);
    }

    public function testLogoutWithoutTokenFails(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/v2/auth/logout');

        $code = $client->getResponse()->getStatusCode();
        $this->assertTrue(\in_array($code, [401, 403], true), "Expected 401 or 403, got {$code}");
    }

    // --- Protected route access ---

    public function testProtectedEndpointRequiresAuth(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/v2/auth/refresh');

        $code = $client->getResponse()->getStatusCode();
        $this->assertTrue(\in_array($code, [401, 403], true), "Expected 401 or 403, got {$code}");
    }

    public function testLoginEndpointRemainsPublic(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/v2/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], (string) json_encode(['username' => 'admin', 'password' => 'admin']));

        $this->assertResponseIsSuccessful();
    }

    public function testHealthEndpointRemainsPublic(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v2/health');

        $this->assertResponseIsSuccessful();
    }
}
