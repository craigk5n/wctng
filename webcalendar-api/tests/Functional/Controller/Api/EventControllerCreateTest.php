<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Tests\Functional\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EventControllerCreateTest extends WebTestCase
{
    use ApiTestTrait;

    public function testCreateMinimalEvent(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('POST', '/api/v2/events', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode([
            'title' => 'Minimal Event',
            'start_date' => '20260701',
        ]));

        $this->assertResponseStatusCodeSame(201);
        $body = $this->decodeResponse($client);

        $this->assertSame('Minimal Event', $body['data']['title']);
        $this->assertArrayHasKey('id', $body['data']);
        $this->assertGreaterThan(0, $body['data']['id']);
        $this->assertTrue($body['data']['all_day']);
        $this->assertNull($body['error']);
    }

    public function testCreateTimedEvent(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('POST', '/api/v2/events', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode([
            'title' => 'Meeting',
            'start_date' => '20260702',
            'start_time' => '100000',
            'duration' => 60,
            'location' => 'Room A',
            'description' => 'Weekly sync',
        ]));

        $this->assertResponseStatusCodeSame(201);
        $body = $this->decodeResponse($client);

        $this->assertSame('Meeting', $body['data']['title']);
        $this->assertSame('100000', $body['data']['start_time']);
        $this->assertSame(60, $body['data']['duration']);
        $this->assertSame('Room A', $body['data']['location']);
        $this->assertSame('Weekly sync', $body['data']['description']);
        $this->assertFalse($body['data']['all_day']);
    }

    public function testCreateSetsCreatedBy(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('POST', '/api/v2/events', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode([
            'title' => 'Owned Event',
            'start_date' => '20260703',
        ]));

        $body = $this->decodeResponse($client);
        $this->assertSame('admin', $body['data']['created_by']);
    }

    public function testCreateWithoutTitleReturns400(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('POST', '/api/v2/events', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode(['start_date' => '20260704']));

        $this->assertResponseStatusCodeSame(400);
        $body = $this->decodeResponse($client);
        $this->assertNotNull($body['error']);
    }

    public function testCreateWithoutStartDateReturns400(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('POST', '/api/v2/events', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode(['title' => 'No Date']));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateWithoutAuthReturns401(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/v2/events', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], (string) json_encode(['title' => 'Test', 'start_date' => '20260705']));

        $code = $client->getResponse()->getStatusCode();
        $this->assertTrue(\in_array($code, [401, 403], true));
    }

    public function testCreateWithAccessLevel(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('POST', '/api/v2/events', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode([
            'title' => 'Private Event',
            'start_date' => '20260706',
            'access' => 'R',
        ]));

        $this->assertResponseStatusCodeSame(201);
        $body = $this->decodeResponse($client);
        $this->assertSame('R', $body['data']['access']);
    }

    public function testCreateReturns201StatusCode(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('POST', '/api/v2/events', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode([
            'title' => 'Status Check',
            'start_date' => '20260707',
        ]));

        $this->assertResponseStatusCodeSame(201);
        $this->assertResponseHeaderSame('Content-Type', 'application/json');
    }
}
