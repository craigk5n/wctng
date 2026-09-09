<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Tests\Functional\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EventControllerDeleteTest extends WebTestCase
{
    use ApiTestTrait;

    #[\Override]
    protected function tearDown(): void
    {
        $this->cleanupTestData();
        parent::tearDown();
    }

    public function testDeleteEvent(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);
        $eventId = $this->createTestEvent($client, $token);

        $client->request('DELETE', "/api/v2/events/{$eventId}", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        // Cancellation is a soft-delete: 200 with the result object, not 204.
        // The web client reads `action`/`previous_status` to offer an undo.
        $this->assertResponseStatusCodeSame(200);
        $body = $this->decodeResponse($client);
        $this->assertSame('cancelled', $body['action']);
        $this->assertArrayHasKey('previous_status', $body);

        // Soft-delete: the row survives so the client can undo, and the
        // event reports its cancelled status rather than 404.
        $client->request('GET', "/api/v2/events/{$eventId}", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertSame('cancelled', $this->decodeResponse($client)['data']['status']);
    }

    public function testDeleteNonexistentReturns404(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('DELETE', '/api/v2/events/999999', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testDeleteRequiresAuth(): void
    {
        $client = static::createClient();
        $client->request('DELETE', '/api/v2/events/1');

        $code = $client->getResponse()->getStatusCode();
        $this->assertTrue(\in_array($code, [401, 403], true));
    }

    public function testAdminCanDeleteAnyEvent(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);
        $eventId = $this->createTestEvent($client, $token, [
            'title' => 'Admin Delete Test',
            'start_date' => '20260901',
        ]);

        // Admin deletes own event (admin is the only user for now)
        $client->request('DELETE', "/api/v2/events/{$eventId}", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('cancelled', $this->decodeResponse($client)['action']);
    }

    public function testDeleteReturnsCancellationBody(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);
        $eventId = $this->createTestEvent($client, $token);

        $client->request('DELETE', "/api/v2/events/{$eventId}", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseStatusCodeSame(200);
        $body = $this->decodeResponse($client);
        $this->assertSame('cancelled', $body['action']);
        $this->assertArrayHasKey('previous_status', $body);
    }
}
