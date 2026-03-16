<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AuthControllerTest extends WebTestCase
{
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
}
