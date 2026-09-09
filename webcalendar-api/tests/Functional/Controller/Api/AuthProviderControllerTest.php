<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Tests\Functional\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional rather than unit, deliberately: these run against the configured
 * DATABASE_URL, so they exercise MySQL. OAuthProviderRepository shipped a
 * SQLite-only CREATE TABLE, which meant oauth_providers was never created and
 * every write 500'd on MySQL while the SQLite-backed unit tests stayed green.
 */
final class AuthProviderControllerTest extends WebTestCase
{
    use ApiTestTrait;

    protected function tearDown(): void
    {
        $this->cleanupTestData();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createProvider(
        \Symfony\Bundle\FrameworkBundle\KernelBrowser $client,
        string $token,
        array $overrides = [],
    ): void {
        $client->request('POST', '/api/v2/admin/auth-providers', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode(array_merge([
            'name' => 'probe-' . bin2hex(random_bytes(4)),
            'client_id' => 'test-client',
            // RFC 5737 documentation range: passes the public-address check
            // without a DNS lookup, and is never routable. The suite runs with
            // APP_MODE=hosted, so a name that does not resolve is refused.
            'token_url' => 'https://192.0.2.1/token',
        ], $overrides)));
    }

    public function testCreateProviderSucceedsOnTheConfiguredDatabase(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $this->createProvider($client, $token);

        $this->assertResponseStatusCodeSame(201);

        $body = $this->decodeResponse($client);
        $this->assertIsArray($body['data']);
        $id = $body['data']['id'];
        $this->assertIsInt($id);

        // Cleaned up here rather than in tearDown: the kernel boots once per
        // test, so tearDown cannot open a second client.
        $client->request('DELETE', '/api/v2/admin/auth-providers/' . $id, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        $this->assertResponseStatusCodeSame(204);
    }

    public function testCreateProviderRejectsNonHttpTokenUrl(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $this->createProvider($client, $token, ['token_url' => 'gopher://127.0.0.1:11211/']);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateProviderRejectsCredentialsInTokenUrl(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $this->createProvider($client, $token, ['token_url' => 'https://user:pass@192.0.2.1/token']);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateProviderRejectsInternalTokenUrlInHostedMode(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        // The suite forces APP_MODE=hosted, so the network rules apply here.
        $this->createProvider($client, $token, ['token_url' => 'http://169.254.169.254/latest/']);

        $this->assertResponseStatusCodeSame(400);
    }
}
