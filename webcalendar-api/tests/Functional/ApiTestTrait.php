<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Shared helpers for functional API tests.
 */
trait ApiTestTrait
{
    private function loginAndGetToken(KernelBrowser $client, string $username = 'admin', string $password = 'admin'): string
    {
        $client->request('POST', '/api/v2/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], (string) json_encode(['username' => $username, 'password' => $password]));

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);

        /** @var array{data: array{token: string}} $body */
        $body = json_decode($content, true);
        self::assertIsArray($body);

        return $body['data']['token'];
    }

    /**
     * @param array<string, mixed> $eventData
     */
    private function createTestEvent(KernelBrowser $client, string $token, array $eventData = []): int
    {
        $defaults = [
            'title' => 'Test Event',
            'start_date' => '20260315',
            'start_time' => '100000',
            'duration' => 60,
        ];

        $client->request('POST', '/api/v2/events', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode(array_merge($defaults, $eventData)));

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);

        /** @var array{data: array{id: int}} $body */
        $body = json_decode($content, true);
        self::assertIsArray($body);

        return $body['data']['id'];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(KernelBrowser $client): array
    {
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);

        $body = json_decode($content, true);
        self::assertIsArray($body);

        /** @var array<string, mixed> $body */
        return $body;
    }
}
