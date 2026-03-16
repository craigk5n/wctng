<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Tests\Functional\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EventControllerDeleteTest extends WebTestCase
{
    use ApiTestTrait;

    public function testDeleteEvent(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);
        $eventId = $this->createTestEvent($client, $token);

        $client->request('DELETE', "/api/v2/events/{$eventId}", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseStatusCodeSame(204);

        // Verify it's gone
        $client->request('GET', "/api/v2/events/{$eventId}", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        $this->assertResponseStatusCodeSame(404);
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

        $this->assertResponseStatusCodeSame(204);
    }

    public function testDeleteReturnsNoBody(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);
        $eventId = $this->createTestEvent($client, $token);

        $client->request('DELETE', "/api/v2/events/{$eventId}", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseStatusCodeSame(204);
        $this->assertSame('', $client->getResponse()->getContent());
    }
}
