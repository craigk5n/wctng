<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Tests\Functional\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EventApproveRejectTest extends WebTestCase
{
    use ApiTestTrait;

    /**
     * Creates a regular user and returns [login, token].
     *
     * @return array{string, string}
     */
    private function createRegularUserAndLogin(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, string $adminToken): array
    {
        $login = 'rsvp_' . bin2hex(random_bytes(3));

        $client->request('POST', '/api/v2/users', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $adminToken,
        ], (string) json_encode([
            'login' => $login,
            'password' => 'Pass123!',
            'email' => $login . '@example.com',
        ]));

        // Login as the new user
        $client->request('POST', '/api/v2/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], (string) json_encode(['username' => $login, 'password' => 'Pass123!']));

        $body = $this->decodeResponse($client);

        return [$login, $body['data']['token']];
    }

    public function testApproveEvent(): void
    {
        $client = static::createClient();
        $adminToken = $this->loginAndGetToken($client);
        $eventId = $this->createTestEvent($client, $adminToken);

        // Create user and add as participant
        [$userLogin, $userToken] = $this->createRegularUserAndLogin($client, $adminToken);

        $client->request('POST', "/api/v2/events/{$eventId}/participants", [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $adminToken,
        ], (string) json_encode(['participants' => [$userLogin]]));

        // User approves
        $client->request('POST', "/api/v2/events/{$eventId}/approve", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $userToken,
        ]);

        $this->assertResponseIsSuccessful();
        $body = $this->decodeResponse($client);
        $this->assertSame('A', $body['data']['status']);
    }

    public function testRejectEvent(): void
    {
        $client = static::createClient();
        $adminToken = $this->loginAndGetToken($client);
        $eventId = $this->createTestEvent($client, $adminToken);

        [$userLogin, $userToken] = $this->createRegularUserAndLogin($client, $adminToken);

        $client->request('POST', "/api/v2/events/{$eventId}/participants", [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $adminToken,
        ], (string) json_encode(['participants' => [$userLogin]]));

        // User rejects
        $client->request('POST', "/api/v2/events/{$eventId}/reject", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $userToken,
        ]);

        $this->assertResponseIsSuccessful();
        $body = $this->decodeResponse($client);
        $this->assertSame('R', $body['data']['status']);
    }

    public function testApproveRequiresAuth(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/v2/events/1/approve');

        $code = $client->getResponse()->getStatusCode();
        $this->assertTrue(\in_array($code, [401, 403], true));
    }

    public function testApproveNonexistentEvent(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('POST', '/api/v2/events/999999/approve', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testApproveNonParticipantReturns400(): void
    {
        $client = static::createClient();
        $adminToken = $this->loginAndGetToken($client);
        $eventId = $this->createTestEvent($client, $adminToken);

        // Create a user who is NOT a participant
        [$_login, $userToken] = $this->createRegularUserAndLogin($client, $adminToken);

        $client->request('POST', "/api/v2/events/{$eventId}/approve", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $userToken,
        ]);

        // Should fail — user is not a participant
        $code = $client->getResponse()->getStatusCode();
        $this->assertTrue(\in_array($code, [400, 403], true));
    }
}
