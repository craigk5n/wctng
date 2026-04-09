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
        $token = $this->loginAndGetToken($client, 'alice', 'password');

        $client->request('POST', '/api/v2/admin/events/purge', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode(['before_date' => '2025-01-01']));

        $this->assertResponseStatusCodeSame(403);
    }
}
