<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Tests\Functional\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TaskControllerTest extends WebTestCase
{
    use ApiTestTrait;

    #[\Override]
    protected function tearDown(): void
    {
        $this->cleanupTestData();
        parent::tearDown();
    }

    private function createTask(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, string $token, string $title = 'Test Task'): int
    {
        $client->request('POST', '/api/v2/tasks', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode([
            'title' => $title,
            'due_date' => '20260401',
            'priority' => 5,
        ]));

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        /** @var array{data: array{id: int}} $body */
        $body = json_decode($content, true);
        self::assertIsArray($body);

        return $body['data']['id'];
    }

    public function testCreateTask(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('POST', '/api/v2/tasks', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode([
            'title' => 'Write docs',
            'due_date' => '20260415',
            'priority' => 3,
        ]));

        $this->assertResponseStatusCodeSame(201);
        $body = $this->decodeResponse($client);
        $this->assertSame('Write docs', $body['data']['title']);
        $this->assertArrayHasKey('id', $body['data']);
        $this->assertArrayHasKey('due_date', $body['data']);
        $this->assertArrayHasKey('percent_complete', $body['data']);
        $this->assertSame(0, $body['data']['percent_complete']);
    }

    public function testListTasks(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);
        $this->createTask($client, $token, 'List Task');

        $client->request('GET', '/api/v2/tasks?start=20260101&end=20261231', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseIsSuccessful();
        $body = $this->decodeResponse($client);
        $this->assertIsArray($body['data']);
    }

    public function testGetTask(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);
        $taskId = $this->createTask($client, $token, 'Get Task');

        $client->request('GET', "/api/v2/tasks/{$taskId}", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseIsSuccessful();
        $body = $this->decodeResponse($client);
        $this->assertSame($taskId, $body['data']['id']);
        $this->assertSame('Get Task', $body['data']['title']);
    }

    public function testUpdateTask(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);
        $taskId = $this->createTask($client, $token);

        $client->request('PUT', "/api/v2/tasks/{$taskId}", [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode(['percent_complete' => 50]));

        $this->assertResponseIsSuccessful();
        $body = $this->decodeResponse($client);
        $this->assertSame(50, $body['data']['percent_complete']);
    }

    public function testCompleteTask(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);
        $taskId = $this->createTask($client, $token);

        $client->request('PUT', "/api/v2/tasks/{$taskId}", [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode(['percent_complete' => 100]));

        $this->assertResponseIsSuccessful();
        $body = $this->decodeResponse($client);
        $this->assertSame(100, $body['data']['percent_complete']);
    }

    public function testDeleteTask(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);
        $taskId = $this->createTask($client, $token);

        $client->request('DELETE', "/api/v2/tasks/{$taskId}", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseStatusCodeSame(204);

        // Verify gone
        $client->request('GET', "/api/v2/tasks/{$taskId}", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testTaskNotFound(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('GET', '/api/v2/tasks/999999', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testTaskRequiresAuth(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v2/tasks');

        $code = $client->getResponse()->getStatusCode();
        $this->assertTrue(\in_array($code, [401, 403], true));
    }
}
