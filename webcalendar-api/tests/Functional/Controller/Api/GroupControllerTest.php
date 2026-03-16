<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Tests\Functional\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GroupControllerTest extends WebTestCase
{
    use ApiTestTrait;

    private function createGroup(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, string $token, string $name = 'Test Group'): int
    {
        $client->request('POST', '/api/v2/groups', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode(['name' => $name]));

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        /** @var array{data: array{id: int}} $body */
        $body = json_decode($content, true);
        self::assertIsArray($body);

        return $body['data']['id'];
    }

    public function testCreateGroup(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('POST', '/api/v2/groups', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode(['name' => 'Engineering']));

        $this->assertResponseStatusCodeSame(201);
        $body = $this->decodeResponse($client);
        $this->assertSame('Engineering', $body['data']['name']);
        $this->assertArrayHasKey('id', $body['data']);
    }

    public function testListGroups(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);
        $this->createGroup($client, $token, 'List Group');

        $client->request('GET', '/api/v2/groups', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseIsSuccessful();
        $body = $this->decodeResponse($client);
        $this->assertIsArray($body['data']);
        $this->assertNotEmpty($body['data']);
    }

    public function testGetGroupWithMembers(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);
        $groupId = $this->createGroup($client, $token, 'Members Group');

        $client->request('GET', "/api/v2/groups/{$groupId}", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseIsSuccessful();
        $body = $this->decodeResponse($client);
        $this->assertSame($groupId, $body['data']['id']);
        $this->assertArrayHasKey('members', $body['data']);
    }

    public function testDeleteGroup(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);
        $groupId = $this->createGroup($client, $token, 'Delete Group');

        $client->request('DELETE', "/api/v2/groups/{$groupId}", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        $this->assertResponseStatusCodeSame(204);
    }

    public function testAddAndRemoveMember(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);
        $groupId = $this->createGroup($client, $token, 'Member Group');

        // Create a user to add
        $login = 'grp_user_' . bin2hex(random_bytes(3));
        $client->request('POST', '/api/v2/users', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode([
            'login' => $login,
            'password' => 'Pass123!',
            'email' => $login . '@example.com',
        ]));

        // Add member
        $client->request('POST', "/api/v2/groups/{$groupId}/members", [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode(['users' => [$login]]));

        $this->assertResponseIsSuccessful();

        // Verify member in group
        $client->request('GET', "/api/v2/groups/{$groupId}", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        $body = $this->decodeResponse($client);
        $this->assertContains($login, $body['data']['members']);

        // Remove member
        $client->request('DELETE', "/api/v2/groups/{$groupId}/members/{$login}", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        $this->assertResponseStatusCodeSame(204);
    }

    public function testGroupRequiresAuth(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v2/groups');

        $code = $client->getResponse()->getStatusCode();
        $this->assertTrue(\in_array($code, [401, 403], true));
    }
}
