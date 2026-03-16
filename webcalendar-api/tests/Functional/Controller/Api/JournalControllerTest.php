<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Tests\Functional\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class JournalControllerTest extends WebTestCase
{
    use ApiTestTrait;

    private function createJournal(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, string $token, string $title = 'Test Journal'): int
    {
        $client->request('POST', '/api/v2/journals', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode([
            'title' => $title,
            'date' => '20260501',
            'text' => 'Journal content here',
        ]));

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        /** @var array{data: array{id: int}} $body */
        $body = json_decode($content, true);
        self::assertIsArray($body);

        return $body['data']['id'];
    }

    public function testCreateJournal(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('POST', '/api/v2/journals', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode([
            'title' => 'My Notes',
            'date' => '20260515',
            'text' => 'Today was productive',
        ]));

        $this->assertResponseStatusCodeSame(201);
        $body = $this->decodeResponse($client);
        $this->assertSame('My Notes', $body['data']['title']);
        $this->assertSame('Today was productive', $body['data']['text']);
        $this->assertArrayHasKey('id', $body['data']);
        $this->assertArrayHasKey('date', $body['data']);
    }

    public function testListJournals(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);
        $this->createJournal($client, $token, 'Listed Journal');

        $client->request('GET', '/api/v2/journals?start=20260401&end=20260601', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseIsSuccessful();
        $body = $this->decodeResponse($client);
        $this->assertIsArray($body['data']);
    }

    public function testGetJournal(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);
        $journalId = $this->createJournal($client, $token, 'Get Journal');

        $client->request('GET', "/api/v2/journals/{$journalId}", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseIsSuccessful();
        $body = $this->decodeResponse($client);
        $this->assertSame($journalId, $body['data']['id']);
        $this->assertSame('Get Journal', $body['data']['title']);
    }

    public function testUpdateJournal(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);
        $journalId = $this->createJournal($client, $token);

        $client->request('PUT', "/api/v2/journals/{$journalId}", [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode(['title' => 'Updated Journal', 'text' => 'New content']));

        $this->assertResponseIsSuccessful();
        $body = $this->decodeResponse($client);
        $this->assertSame('Updated Journal', $body['data']['title']);
        $this->assertSame('New content', $body['data']['text']);
    }

    public function testDeleteJournal(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);
        $journalId = $this->createJournal($client, $token);

        $client->request('DELETE', "/api/v2/journals/{$journalId}", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        $this->assertResponseStatusCodeSame(204);

        $client->request('GET', "/api/v2/journals/{$journalId}", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testJournalNotFound(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('GET', '/api/v2/journals/999999', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testJournalRequiresAuth(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v2/journals?start=20260101&end=20261231');

        $code = $client->getResponse()->getStatusCode();
        $this->assertTrue(\in_array($code, [401, 403], true));
    }
}
