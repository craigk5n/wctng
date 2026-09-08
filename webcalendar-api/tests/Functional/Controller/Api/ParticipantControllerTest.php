<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Tests\Functional\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ParticipantControllerTest extends WebTestCase
{
    use ApiTestTrait;

    protected function tearDown(): void
    {
        $this->cleanupTestData();
        parent::tearDown();
    }

    public function testListParticipantsForEvent(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);
        $eventId = $this->createTestEvent($client, $token);

        $client->request('GET', "/api/v2/events/{$eventId}/participants", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseIsSuccessful();
        $body = $this->decodeResponse($client);
        $this->assertIsArray($body['data']);
    }

    public function testAddParticipants(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);
        $eventId = $this->createTestEvent($client, $token);

        // Create a regular user first
        $login = 'part_user_' . bin2hex(random_bytes(3));
        $client->request('POST', '/api/v2/users', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode([
            'login' => $login,
            'password' => 'Pass123!',
            'email' => $login . '@example.com',
        ]));
        $this->trackUser($login);

        $client->request('POST', "/api/v2/events/{$eventId}/participants", [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode(['participants' => [$login]]));

        $this->assertResponseIsSuccessful();

        // Verify participant was added
        $client->request('GET', "/api/v2/events/{$eventId}/participants", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        $body = $this->decodeResponse($client);
        $logins = array_column($body['data'], 'login');
        $this->assertContains($login, $logins);
    }

    public function testRemoveParticipant(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);
        $eventId = $this->createTestEvent($client, $token);

        $login = 'rem_user_' . bin2hex(random_bytes(3));
        $client->request('POST', '/api/v2/users', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode([
            'login' => $login,
            'password' => 'Pass123!',
            'email' => $login . '@example.com',
        ]));
        $this->trackUser($login);

        // Add then remove
        $client->request('POST', "/api/v2/events/{$eventId}/participants", [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode(['participants' => [$login]]));

        $client->request('DELETE', "/api/v2/events/{$eventId}/participants/{$login}", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseStatusCodeSame(204);
    }

    public function testUpdateParticipantStatus(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);
        $eventId = $this->createTestEvent($client, $token);

        $login = 'stat_user_' . bin2hex(random_bytes(3));
        $client->request('POST', '/api/v2/users', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode([
            'login' => $login,
            'password' => 'Pass123!',
            'email' => $login . '@example.com',
        ]));
        $this->trackUser($login);

        // Add participant
        $client->request('POST', "/api/v2/events/{$eventId}/participants", [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode(['participants' => [$login]]));

        // Update status
        $client->request('PUT', "/api/v2/events/{$eventId}/participants/{$login}", [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode(['status' => 'A']));

        $this->assertResponseIsSuccessful();
        $body = $this->decodeResponse($client);
        $this->assertSame('A', $body['data']['status']);
    }

    public function testParticipantsRequiresAuth(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v2/events/1/participants');

        $code = $client->getResponse()->getStatusCode();
        $this->assertTrue(\in_array($code, [401, 403], true));
    }

    public function testEventNotFoundReturns404(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('GET', '/api/v2/events/999999/participants', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseStatusCodeSame(404);
    }
}
