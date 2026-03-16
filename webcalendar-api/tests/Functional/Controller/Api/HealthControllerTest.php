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
}
