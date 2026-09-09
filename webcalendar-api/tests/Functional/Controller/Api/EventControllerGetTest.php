<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Tests\Functional\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EventControllerGetTest extends WebTestCase
{
    use ApiTestTrait;

    #[\Override]
    protected function tearDown(): void
    {
        $this->cleanupTestData();
        parent::tearDown();
    }

    public function testGetExistingEvent(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);
        $eventId = $this->createTestEvent($client, $token, [
            'title' => 'Get Test Event',
            'start_date' => '20260501',
            'start_time' => '143000',
            'duration' => 45,
            'location' => 'Room C',
            'description' => 'Event for get test',
        ]);

        $client->request('GET', "/api/v2/events/{$eventId}", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseIsSuccessful();
        $body = $this->decodeResponse($client);

        $this->assertSame($eventId, $body['data']['id']);
        $this->assertSame('Get Test Event', $body['data']['title']);
        $this->assertSame('Event for get test', $body['data']['description']);
        $this->assertSame('20260501', $body['data']['start_date']);
        $this->assertSame('143000', $body['data']['start_time']);
        $this->assertSame(45, $body['data']['duration']);
        $this->assertSame('Room C', $body['data']['location']);
        $this->assertSame('P', $body['data']['access']);
        $this->assertSame('E', $body['data']['type']);
        $this->assertSame('admin', $body['data']['created_by']);
        $this->assertNull($body['error']);
    }

    public function testGetReturnsJsonContentType(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);
        $eventId = $this->createTestEvent($client, $token);

        $client->request('GET', "/api/v2/events/{$eventId}", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseHeaderSame('Content-Type', 'application/json');
    }

    public function testGetNonexistentEventReturns404(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('GET', '/api/v2/events/999999', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseStatusCodeSame(404);
        $body = $this->decodeResponse($client);
        $this->assertNull($body['data']);
        $this->assertSame(404, $body['error']['code']);
    }

    public function testGetRequiresAuth(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v2/events/1');

        $code = $client->getResponse()->getStatusCode();
        $this->assertTrue(\in_array($code, [401, 403], true));
    }

    public function testGetAllDayEvent(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        // Send directly without defaults to avoid start_time being included
        $client->request('POST', '/api/v2/events', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode(['title' => 'All Day Get Test', 'start_date' => '20260502']));
        $body = $this->decodeResponse($client);
        /** @var int $eventId */
        $eventId = $body['data']['id'];

        $client->request('GET', "/api/v2/events/{$eventId}", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseIsSuccessful();
        $body = $this->decodeResponse($client);

        $this->assertSame('All Day Get Test', $body['data']['title']);
        $this->assertTrue($body['data']['all_day']);
        $this->assertNull($body['data']['start_time']);
    }
}
