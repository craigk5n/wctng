<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Tests\Functional\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EventControllerListTest extends WebTestCase
{
    use ApiTestTrait;

    public function testListRequiresAuth(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v2/events');

        $code = $client->getResponse()->getStatusCode();
        $this->assertTrue(\in_array($code, [401, 403], true));
    }

    public function testListReturnsEventsInEnvelope(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);
        $this->createTestEvent($client, $token);

        $client->request('GET', '/api/v2/events?start=20260101&end=20261231', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseIsSuccessful();
        $body = $this->decodeResponse($client);

        $this->assertIsArray($body['data']);
        $this->assertArrayHasKey('meta', $body);
        $this->assertIsArray($body['meta']);
        $this->assertArrayHasKey('total', $body['meta']);
        $this->assertArrayHasKey('page', $body['meta']);
        $this->assertArrayHasKey('limit', $body['meta']);
        $this->assertNull($body['error']);
    }

    public function testListReturnsEmptyArrayWhenNoEvents(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('GET', '/api/v2/events?start=19000101&end=19001231', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseIsSuccessful();
        $body = $this->decodeResponse($client);

        $this->assertSame([], $body['data']);
        $this->assertSame(0, $body['meta']['total']);
    }

    public function testListReturnsJsonContentType(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('GET', '/api/v2/events?start=20260101&end=20261231', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseHeaderSame('Content-Type', 'application/json');
    }

    public function testListDefaultPagination(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('GET', '/api/v2/events?start=20260101&end=20261231', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $body = $this->decodeResponse($client);
        $this->assertSame(1, $body['meta']['page']);
        $this->assertSame(20, $body['meta']['limit']);
    }

    public function testListCustomPagination(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('GET', '/api/v2/events?start=20260101&end=20261231&page=2&limit=5', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseIsSuccessful();
        $body = $this->decodeResponse($client);
        $this->assertSame(2, $body['meta']['page']);
        $this->assertSame(5, $body['meta']['limit']);
    }

    public function testListEventHasExpectedFields(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);
        $this->createTestEvent($client, $token, [
            'title' => 'Field Check Event',
            'start_date' => '20260601',
            'start_time' => '140000',
            'duration' => 30,
            'location' => 'Office',
            'description' => 'Testing fields',
        ]);

        $client->request('GET', '/api/v2/events?start=20260601&end=20260601', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $body = $this->decodeResponse($client);
        $this->assertNotEmpty($body['data']);

        $event = $body['data'][0];
        $this->assertArrayHasKey('id', $event);
        $this->assertArrayHasKey('title', $event);
        $this->assertArrayHasKey('description', $event);
        $this->assertArrayHasKey('start_date', $event);
        $this->assertArrayHasKey('start_time', $event);
        $this->assertArrayHasKey('duration', $event);
        $this->assertArrayHasKey('location', $event);
        $this->assertArrayHasKey('access', $event);
        $this->assertArrayHasKey('type', $event);
        $this->assertArrayHasKey('created_by', $event);
    }
}
