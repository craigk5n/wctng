<?php

declare(strict_types=1);

namespace App\Tests\Functional\EventSubscriber;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ExceptionSubscriberTest extends WebTestCase
{
    public function testNotFoundReturns404Envelope(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v2/nonexistent');

        $this->assertResponseStatusCodeSame(404);

        $content = $client->getResponse()->getContent();
        $this->assertIsString($content);

        /** @var array{data: null, meta: null, error: array{code: int, message: string, details: list<string>}} $body */
        $body = json_decode($content, true);

        $this->assertIsArray($body);
        $this->assertNull($body['data']);
        $this->assertNull($body['meta']);
        $this->assertSame(404, $body['error']['code']);
        $this->assertIsString($body['error']['message']);
        $this->assertIsArray($body['error']['details']);
    }

    public function testNotFoundReturnsJsonContentType(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v2/nonexistent');

        $this->assertResponseHeaderSame('Content-Type', 'application/json');
    }

    public function testMethodNotAllowedReturns405Envelope(): void
    {
        $client = static::createClient();
        $client->request('DELETE', '/api/v2/health');

        $this->assertResponseStatusCodeSame(405);

        $content = $client->getResponse()->getContent();
        $this->assertIsString($content);

        /** @var array{error: array{code: int}} $body */
        $body = json_decode($content, true);

        $this->assertIsArray($body);
        $this->assertSame(405, $body['error']['code']);
    }
}
