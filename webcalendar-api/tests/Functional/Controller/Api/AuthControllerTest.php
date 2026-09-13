<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Service\CoreServiceFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AuthControllerTest extends WebTestCase
{
    /**
     * @return array{username?: string, is_admin?: bool, exp?: int, rem?: bool, tenant?: string}
     */
    private function decodeJwtPayload(string $token): array
    {
        $parts = explode('.', $token);
        self::assertCount(3, $parts);
        $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
        self::assertIsString($json);
        /** @var array{username?: string, is_admin?: bool, exp?: int, rem?: bool, tenant?: string} $payload */
        $payload = json_decode($json, true);
        self::assertIsArray($payload);

        return $payload;
    }

    private function performLogin(KernelBrowser $client, bool $rememberMe): string
    {
        $client->request('POST', '/api/v2/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], (string) json_encode([
            'username' => 'admin',
            'password' => 'admin',
            'remember_me' => $rememberMe,
        ]));

        $this->assertResponseIsSuccessful();
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);

        /** @var array{data: array{token: string}} $body */
        $body = json_decode($content, true);
        self::assertIsArray($body);

        return $body['data']['token'];
    }

    public function testLoginSuccess(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/v2/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], (string) json_encode(['username' => 'admin', 'password' => 'admin']));

        $this->assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        $this->assertIsString($content);

        /** @var array{data: array{token: string, user: array{login: string, firstname: string, lastname: string, email: string, is_admin: bool}, expires_at: string}} $body */
        $body = json_decode($content, true);
        $this->assertIsArray($body);

        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('token', $body['data']);
        $this->assertNotEmpty($body['data']['token']);
        $this->assertArrayHasKey('user', $body['data']);
        $this->assertSame('admin', $body['data']['user']['login']);
        $this->assertTrue($body['data']['user']['is_admin']);
        $this->assertArrayHasKey('firstname', $body['data']['user']);
        $this->assertArrayHasKey('lastname', $body['data']['user']);
        $this->assertArrayHasKey('email', $body['data']['user']);
        $this->assertArrayHasKey('expires_at', $body['data']);
        $this->assertNull($body['error'] ?? null);
    }

    public function testLoginReturnsJsonContentType(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/v2/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], (string) json_encode(['username' => 'admin', 'password' => 'admin']));

        $this->assertResponseHeaderSame('Content-Type', 'application/json');
    }

    public function testLoginInvalidCredentials(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/v2/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], (string) json_encode(['username' => 'admin', 'password' => 'wrong']));

        $this->assertResponseStatusCodeSame(401);

        $content = $client->getResponse()->getContent();
        $this->assertIsString($content);

        /** @var array{data: null, error: array{code: int, message: string}} $body */
        $body = json_decode($content, true);
        $this->assertIsArray($body);

        $this->assertNull($body['data']);
        $this->assertSame(401, $body['error']['code']);
    }

    public function testLoginNonexistentUser(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/v2/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], (string) json_encode(['username' => 'nobody', 'password' => 'pass']));

        $this->assertResponseStatusCodeSame(401);
    }

    public function testLoginMissingPassword(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/v2/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], (string) json_encode(['username' => 'admin']));

        $this->assertResponseStatusCodeSame(400);

        $content = $client->getResponse()->getContent();
        $this->assertIsString($content);

        /** @var array{error: array{code: int}} $body */
        $body = json_decode($content, true);
        $this->assertIsArray($body);
        $this->assertSame(400, $body['error']['code']);
    }

    public function testLoginMissingUsername(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/v2/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], (string) json_encode(['password' => 'admin']));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testLoginEmptyBody(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/v2/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '{}');

        $this->assertResponseStatusCodeSame(400);
    }

    public function testLoginReturnsValidJwt(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/v2/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], (string) json_encode(['username' => 'admin', 'password' => 'admin']));

        $this->assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        $this->assertIsString($content);

        /** @var array{data: array{token: string}} $body */
        $body = json_decode($content, true);
        $this->assertIsArray($body);

        $token = $body['data']['token'];

        // Token should be a valid JWT (3 dot-separated parts)
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);
    }

    public function testRememberMeGrantsExtendedTtlWhenAllowed(): void
    {
        $client = static::createClient();
        $factory = static::getContainer()->get(CoreServiceFactory::class);
        self::assertInstanceOf(CoreServiceFactory::class, $factory);
        $configService = $factory->getConfigService();

        $previousDisable = $configService->getSetting('DISABLE_REMEMBER_ME');
        $configService->updateSetting('DISABLE_REMEMBER_ME', 'N');

        try {
            $normalTtl = (int) ($configService->getSetting('SESSION_TTL') ?? '28800');
            $rememberTtl = (int) ($configService->getSetting('SESSION_TTL_REMEMBER_ME') ?? '2592000');
            self::assertGreaterThan($normalTtl, $rememberTtl, 'remember-me TTL must exceed normal TTL for this test');

            $token = $this->performLogin($client, rememberMe: true);
            $payload = $this->decodeJwtPayload($token);

            self::assertArrayHasKey('exp', $payload);
            self::assertTrue(($payload['rem'] ?? false) === true, 'token should carry the rem claim when remember-me is allowed');
            $remaining = $payload['exp'] - time();
            self::assertGreaterThan($normalTtl, $remaining, 'exp should reflect the longer remember-me TTL');
        } finally {
            if ($previousDisable === null) {
                $configService->updateSetting('DISABLE_REMEMBER_ME', 'N');
            } else {
                $configService->updateSetting('DISABLE_REMEMBER_ME', $previousDisable);
            }
        }
    }

    public function testDisableRememberMeIgnoresClientFlag(): void
    {
        $client = static::createClient();
        $factory = static::getContainer()->get(CoreServiceFactory::class);
        self::assertInstanceOf(CoreServiceFactory::class, $factory);
        $configService = $factory->getConfigService();

        $previousDisable = $configService->getSetting('DISABLE_REMEMBER_ME');
        $configService->updateSetting('DISABLE_REMEMBER_ME', 'Y');

        try {
            $normalTtl = (int) ($configService->getSetting('SESSION_TTL') ?? '28800');
            $rememberTtl = (int) ($configService->getSetting('SESSION_TTL_REMEMBER_ME') ?? '2592000');
            self::assertGreaterThan($normalTtl, $rememberTtl, 'remember-me TTL must exceed normal TTL for this test');

            $token = $this->performLogin($client, rememberMe: true);
            $payload = $this->decodeJwtPayload($token);

            self::assertArrayHasKey('exp', $payload);
            self::assertFalse(($payload['rem'] ?? false), 'token must not carry the rem claim when remember-me is disabled');

            $remaining = $payload['exp'] - time();
            // Allow 60s clock slack; the exp should be at most the normal TTL
            self::assertLessThanOrEqual($normalTtl + 60, $remaining, 'exp should use normal TTL when remember-me is disabled');
        } finally {
            if ($previousDisable === null) {
                $configService->updateSetting('DISABLE_REMEMBER_ME', 'N');
            } else {
                $configService->updateSetting('DISABLE_REMEMBER_ME', $previousDisable);
            }
        }
    }

    public function testConfigFeaturesEndpointExposesDisableRememberMe(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v2/config/features');

        $this->assertResponseIsSuccessful();
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);

        /** @var array{data: array<string, string>} $body */
        $body = json_decode($content, true);
        self::assertIsArray($body);
        self::assertArrayHasKey('DISABLE_REMEMBER_ME', $body['data']);
        self::assertContains($body['data']['DISABLE_REMEMBER_ME'], ['Y', 'N']);
    }
}
