<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Tests\Functional\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AccessControllerTest extends WebTestCase
{
    use ApiTestTrait;

    protected function tearDown(): void
    {
        $this->cleanupTestData();
        parent::tearDown();
    }

    public function testListAccessEmpty(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('GET', '/api/v2/access/users', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseIsSuccessful();
        $body = $this->decodeResponse($client);
        $this->assertIsArray($body['data']);
    }

    public function testSetAndGetAccess(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        // Create a user to grant access to
        $login = 'access_usr_' . bin2hex(random_bytes(3));
        $client->request('POST', '/api/v2/users', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode([
            'login' => $login,
            'password' => 'Pass123!',
            'email' => $login . '@example.com',
        ]));
        $this->assertResponseStatusCodeSame(201);
        $this->trackUser($login);

        // Set access
        $client->request('PUT', "/api/v2/access/users/{$login}", [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode([
            'can_view' => true,
            'can_edit' => false,
        ]));

        $this->assertResponseIsSuccessful();
        $body = $this->decodeResponse($client);
        $this->assertTrue($body['data']['can_view']);
        $this->assertFalse($body['data']['can_edit']);

        // Verify it appears in the list
        $client->request('GET', '/api/v2/access/users', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $body = $this->decodeResponse($client);
        $found = false;
        foreach ($body['data'] as $entry) {
            if ($entry['login'] === $login) {
                $found = true;
                $this->assertTrue($entry['can_view']);
                $this->assertFalse($entry['can_edit']);
            }
        }
        $this->assertTrue($found, "Expected access entry for {$login}");
    }

    public function testUpdateExistingAccess(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $login = 'access_upd_' . bin2hex(random_bytes(3));
        $client->request('POST', '/api/v2/users', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode([
            'login' => $login,
            'password' => 'Pass123!',
            'email' => $login . '@example.com',
        ]));
        $this->trackUser($login);

        // Set initial access
        $client->request('PUT', "/api/v2/access/users/{$login}", [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode(['can_view' => true, 'can_edit' => false]));

        // Update to add edit access
        $client->request('PUT', "/api/v2/access/users/{$login}", [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode(['can_view' => true, 'can_edit' => true]));

        $body = $this->decodeResponse($client);
        $this->assertTrue($body['data']['can_edit']);
    }

    public function testAccessRequiresAuth(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v2/access/users');

        $code = $client->getResponse()->getStatusCode();
        $this->assertTrue(\in_array($code, [401, 403], true));
    }
}
