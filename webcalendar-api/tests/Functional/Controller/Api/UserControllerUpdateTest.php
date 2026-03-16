<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Tests\Functional\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class UserControllerUpdateTest extends WebTestCase
{
    use ApiTestTrait;

    /**
     * @return array{login: string, password: string, admin_token: string}
     */
    private function createRegularUser(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, string $adminToken): array
    {
        $login = 'regular_' . bin2hex(random_bytes(4));

        $client->request('POST', '/api/v2/users', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $adminToken,
        ], (string) json_encode([
            'login' => $login,
            'password' => 'RegularPass1!',
            'email' => $login . '@example.com',
            'firstname' => 'Regular',
            'lastname' => 'User',
        ]));

        return ['login' => $login, 'password' => 'RegularPass1!', 'admin_token' => $adminToken];
    }

    // --- Update Profile ---

    public function testAdminUpdateOwnProfile(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('PUT', '/api/v2/users/admin', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode(['firstname' => 'SuperAdmin']));

        $this->assertResponseIsSuccessful();
        $body = $this->decodeResponse($client);
        $this->assertSame('SuperAdmin', $body['data']['firstname']);
    }

    public function testUpdateNonexistentUserReturns404(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('PUT', '/api/v2/users/nobody_xyz', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode(['firstname' => 'X']));

        $this->assertResponseStatusCodeSame(404);
    }

    public function testUpdateRequiresAuth(): void
    {
        $client = static::createClient();
        $client->request('PUT', '/api/v2/users/admin', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], (string) json_encode(['firstname' => 'X']));

        $code = $client->getResponse()->getStatusCode();
        $this->assertTrue(\in_array($code, [401, 403], true));
    }

    public function testUpdatePreservesUnchangedFields(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        // Get current state
        $client->request('GET', '/api/v2/users/admin', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        $before = $this->decodeResponse($client);

        // Update only firstname
        $client->request('PUT', '/api/v2/users/admin', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode(['firstname' => 'Changed']));

        $body = $this->decodeResponse($client);
        $this->assertSame('Changed', $body['data']['firstname']);
        $this->assertSame($before['data']['lastname'], $body['data']['lastname']);
        $this->assertSame($before['data']['email'], $body['data']['email']);
    }

    // --- Change Password ---

    public function testAdminChangeOwnPassword(): void
    {
        $client = static::createClient();
        $adminToken = $this->loginAndGetToken($client);
        $userInfo = $this->createRegularUser($client, $adminToken);

        $client->request('PUT', "/api/v2/users/{$userInfo['login']}/password", [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $adminToken,
        ], (string) json_encode([
            'new_password' => 'NewAdminPass1!',
        ]));

        $this->assertResponseIsSuccessful();

        // Verify new password works
        $client->request('POST', '/api/v2/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], (string) json_encode([
            'username' => $userInfo['login'],
            'password' => 'NewAdminPass1!',
        ]));

        $this->assertResponseIsSuccessful();
    }

    public function testChangePasswordRequiresNewPassword(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('PUT', '/api/v2/users/admin/password', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode([]));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testChangePasswordRequiresAuth(): void
    {
        $client = static::createClient();
        $client->request('PUT', '/api/v2/users/admin/password', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], (string) json_encode(['new_password' => 'X']));

        $code = $client->getResponse()->getStatusCode();
        $this->assertTrue(\in_array($code, [401, 403], true));
    }

    public function testChangePasswordForNonexistentUser(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('PUT', '/api/v2/users/nobody_xyz/password', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode(['new_password' => 'X']));

        $this->assertResponseStatusCodeSame(404);
    }
}
