<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Tests\Functional\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminEventControllerTest extends WebTestCase
{
    use ApiTestTrait;

    public function testPurgeRequiresAuth(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/v2/admin/events/purge', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], (string) json_encode(['before_date' => '2025-01-01']));

        $code = $client->getResponse()->getStatusCode();
        $this->assertContains($code, [401, 403]);
    }

    public function testPurgeRequiresBeforeDate(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('POST', '/api/v2/admin/events/purge', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode([]));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testPurgeRejectsInvalidBeforeDate(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('POST', '/api/v2/admin/events/purge', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode(['before_date' => 'not-a-date']));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testPurgeDryRunReturnsCount(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('POST', '/api/v2/admin/events/purge', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode([
            'before_date' => '2025-01-01',
            'dry_run' => true,
        ]));

        $this->assertResponseIsSuccessful();
        $body = $this->decodeResponse($client);
        $this->assertArrayHasKey('data', $body);
        $this->assertIsArray($body['data']);
        $this->assertArrayHasKey('count', $body['data']);
        $this->assertArrayHasKey('dry_run', $body['data']);
        $this->assertTrue($body['data']['dry_run']);
    }

    public function testPurgeLiveRunRequiresConfirmCount(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('POST', '/api/v2/admin/events/purge', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode([
            'before_date' => '2025-01-01',
            'dry_run' => false,
        ]));

        $this->assertResponseStatusCodeSame(422);
    }

    public function testPurgeNonAdminForbidden(): void
    {
        $client = static::createClient();
        $adminToken = $this->loginAndGetToken($client);

        // Create the non-admin rather than assuming a seeded 'alice': the only
        // fixture the suite can count on is the admin webcalendar:install makes.
        // Done inline, and torn down inline, because the trait's createTestUser
        // and cleanupTestData helpers are not committed.
        $login = 'purge_nonadmin_' . bin2hex(random_bytes(3));
        $client->request('POST', '/api/v2/users', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $adminToken,
        ], (string) json_encode([
            'login' => $login,
            'password' => 'Pass123!',
            'email' => $login . '@example.com',
        ]));
        $this->assertResponseStatusCodeSame(201);

        $token = $this->loginAndGetToken($client, $login, 'Pass123!');

        $client->request('POST', '/api/v2/admin/events/purge', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode(['before_date' => '2025-01-01']));

        $this->assertResponseStatusCodeSame(403);

        $client->request('DELETE', "/api/v2/users/{$login}", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $adminToken,
        ]);
    }
}
