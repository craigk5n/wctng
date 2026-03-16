<?php

declare(strict_types=1);

namespace App\Tests\Functional\Middleware;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CorsTest extends WebTestCase
{
    public function testPreflightReturnsCorrectHeaders(): void
    {
        $client = static::createClient();
        $client->request('OPTIONS', '/api/v2/health', [], [], [
            'HTTP_ORIGIN' => 'http://localhost:47173',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        $this->assertResponseHeaderSame('Access-Control-Allow-Origin', 'http://localhost:47173');
        $this->assertResponseHeaderSame('Access-Control-Allow-Credentials', 'true');
        $this->assertResponseHeaderSame('Access-Control-Max-Age', '3600');

        $allowMethods = $client->getResponse()->headers->get('Access-Control-Allow-Methods');
        $this->assertIsString($allowMethods);
        $this->assertStringContainsString('GET', $allowMethods);

        $allowHeaders = $client->getResponse()->headers->get('Access-Control-Allow-Headers');
        $this->assertIsString($allowHeaders);
        $this->assertStringContainsStringIgnoringCase('authorization', $allowHeaders);
        $this->assertStringContainsStringIgnoringCase('content-type', $allowHeaders);
    }

    public function testPreflightAllowsAllRequiredMethods(): void
    {
        $client = static::createClient();
        $client->request('OPTIONS', '/api/v2/health', [], [], [
            'HTTP_ORIGIN' => 'http://localhost:47173',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $allowMethods = $client->getResponse()->headers->get('Access-Control-Allow-Methods');
        $this->assertIsString($allowMethods);

        foreach (['GET', 'POST', 'PUT', 'DELETE'] as $method) {
            $this->assertStringContainsString($method, $allowMethods);
        }
    }

    public function testCrossOriginGetReturnsAllowOriginHeader(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v2/health', [], [], [
            'HTTP_ORIGIN' => 'http://localhost:47173',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Access-Control-Allow-Origin', 'http://localhost:47173');
    }

    public function testSameOriginRequestWorksWithoutCorsHeaders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v2/health');

        $this->assertResponseIsSuccessful();
        $this->assertFalse($client->getResponse()->headers->has('Access-Control-Allow-Origin'));
    }

    public function testDisallowedOriginDoesNotGetCorsHeaders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v2/health', [], [], [
            'HTTP_ORIGIN' => 'http://evil.example.com',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertFalse($client->getResponse()->headers->has('Access-Control-Allow-Origin'));
    }
}
