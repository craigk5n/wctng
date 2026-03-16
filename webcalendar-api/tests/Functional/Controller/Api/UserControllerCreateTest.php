<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Tests\Functional\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class UserControllerCreateTest extends WebTestCase
{
    use ApiTestTrait;

    public function testCreateUser(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $login = 'testuser_' . bin2hex(random_bytes(4));
        $client->request('POST', '/api/v2/users', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode([
            'login' => $login,
            'password' => 'SecurePass123!',
            'email' => $login . '@example.com',
            'firstname' => 'Test',
            'lastname' => 'User',
        ]));

        $this->assertResponseStatusCodeSame(201);
        $body = $this->decodeResponse($client);

        $this->assertSame($login, $body['data']['login']);
        $this->assertSame('Test', $body['data']['firstname']);
        $this->assertArrayNotHasKey('password', $body['data']);
        $this->assertArrayNotHasKey('password_hash', $body['data']);
        $this->assertNull($body['error']);
    }

    public function testCreateUserCanLogin(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $login = 'logintest_' . bin2hex(random_bytes(4));
        $client->request('POST', '/api/v2/users', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode([
            'login' => $login,
            'password' => 'TestPass456!',
            'email' => $login . '@example.com',
        ]));

        $this->assertResponseStatusCodeSame(201);

        // Verify new user can log in
        $client->request('POST', '/api/v2/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], (string) json_encode(['username' => $login, 'password' => 'TestPass456!']));

        $this->assertResponseIsSuccessful();
        $body = $this->decodeResponse($client);
        $this->assertSame($login, $body['data']['user']['login']);
    }

    public function testCreateDuplicateLoginReturns409(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('POST', '/api/v2/users', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode([
            'login' => 'admin',
            'password' => 'pass',
            'email' => 'dup@example.com',
        ]));

        $this->assertResponseStatusCodeSame(409);
    }

    public function testCreateMissingLoginReturns400(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('POST', '/api/v2/users', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode([
            'password' => 'pass',
            'email' => 'nologin@example.com',
        ]));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateMissingPasswordReturns400(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('POST', '/api/v2/users', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode([
            'login' => 'nopass_user',
            'email' => 'nopass@example.com',
        ]));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateMissingEmailReturns400(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('POST', '/api/v2/users', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode([
            'login' => 'noemail_user',
            'password' => 'pass',
        ]));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateRequiresAuth(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/v2/users', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], (string) json_encode([
            'login' => 'unauth_user',
            'password' => 'pass',
            'email' => 'unauth@example.com',
        ]));

        $code = $client->getResponse()->getStatusCode();
        $this->assertTrue(\in_array($code, [401, 403], true));
    }
}
