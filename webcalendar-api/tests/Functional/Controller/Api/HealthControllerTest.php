<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthControllerTest extends WebTestCase
{
    public function testHealthEndpointReturnsOk(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v2/health');

        $this->assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        $this->assertIsString($content);

        /** @var array{status: string, timestamp: string} $response */
        $response = json_decode($content, true);

        $this->assertIsArray($response);
        $this->assertSame('ok', $response['status']);
        $this->assertArrayHasKey('timestamp', $response);
    }

    public function testHealthEndpointReturnsJson(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v2/health');

        $this->assertResponseHeaderSame('Content-Type', 'application/json');
    }

    public function testLivenessHasNoComponentsKey(): void
    {
        // PBP-S13: liveness is strictly "PHP process alive" — no DB ping, no
        // component summary. Those moved to /ready.
        $client = static::createClient();
        $client->request('GET', '/api/v2/health');

        $content = $client->getResponse()->getContent();
        $this->assertIsString($content);

        /** @var array<string, mixed> $response */
        $response = (array) json_decode($content, true);
        $this->assertArrayNotHasKey('components', $response);
        $this->assertArrayNotHasKey('recent_errors', $response);
    }

    public function testReadinessEndpointExists(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v2/ready');

        // Readiness may return 503 in the sandbox (no MySQL) — the key
        // assertion is that the route exists and returns JSON with the
        // readiness shape.
        $content = $client->getResponse()->getContent();
        $this->assertIsString($content);

        /** @var array<string, mixed> $response */
        $response = (array) json_decode($content, true);

        $this->assertArrayHasKey('status', $response);
        $this->assertArrayHasKey('components', $response);
        $this->assertIsArray($response['components']);
        $this->assertArrayHasKey('database', $response['components']);
    }
}
