<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Tests\Functional\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EventControllerUpdateTest extends WebTestCase
{
    use ApiTestTrait;

    #[\Override]
    protected function tearDown(): void
    {
        $this->cleanupTestData();
        parent::tearDown();
    }

    public function testUpdateTitle(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);
        $eventId = $this->createTestEvent($client, $token);

        $client->request('PUT', "/api/v2/events/{$eventId}", [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode(['title' => 'Updated Title']));

        $this->assertResponseIsSuccessful();
        $body = $this->decodeResponse($client);
        $this->assertSame('Updated Title', $body['data']['title']);
    }

    public function testUpdateMultipleFields(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);
        $eventId = $this->createTestEvent($client, $token);

        $client->request('PUT', "/api/v2/events/{$eventId}", [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode([
            'title' => 'Multi Update',
            'description' => 'New desc',
            'location' => 'New Room',
        ]));

        $this->assertResponseIsSuccessful();
        $body = $this->decodeResponse($client);
        $this->assertSame('Multi Update', $body['data']['title']);
        $this->assertSame('New desc', $body['data']['description']);
        $this->assertSame('New Room', $body['data']['location']);
    }

    public function testUpdatePreservesUnchangedFields(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);
        $eventId = $this->createTestEvent($client, $token, [
            'title' => 'Original',
            'start_date' => '20260801',
            'start_time' => '140000',
            'duration' => 45,
            'location' => 'Keep This',
        ]);

        $client->request('PUT', "/api/v2/events/{$eventId}", [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode(['title' => 'Changed']));

        $body = $this->decodeResponse($client);
        $this->assertSame('Changed', $body['data']['title']);
        $this->assertSame('Keep This', $body['data']['location']);
        $this->assertSame(45, $body['data']['duration']);
    }

    public function testUpdateNonexistentReturns404(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('PUT', '/api/v2/events/999999', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode(['title' => 'X']));

        $this->assertResponseStatusCodeSame(404);
    }

    public function testUpdateRequiresAuth(): void
    {
        $client = static::createClient();
        $client->request('PUT', '/api/v2/events/1', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], (string) json_encode(['title' => 'X']));

        $code = $client->getResponse()->getStatusCode();
        $this->assertTrue(\in_array($code, [401, 403], true));
    }

    public function testUpdateReturnsJsonContentType(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);
        $eventId = $this->createTestEvent($client, $token);

        $client->request('PUT', "/api/v2/events/{$eventId}", [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode(['title' => 'JSON Check']));

        $this->assertResponseHeaderSame('Content-Type', 'application/json');
    }
}
