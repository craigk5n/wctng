<?php

declare(strict_types=1);

namespace App\Tests\Unit\Response;

use App\Response\ApiResponse;
use PHPUnit\Framework\TestCase;

final class ApiResponseTest extends TestCase
{
    public function testSuccessEnvelope(): void
    {
        $response = ApiResponse::success(['id' => 1]);
        $body = $this->decode($response->getContent());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['id' => 1], $body['data']);
        $this->assertNull($body['meta']);
        $this->assertNull($body['error']);
    }

    public function testSuccessWithMeta(): void
    {
        $response = ApiResponse::success(['id' => 1], ['extra' => 'info']);
        $body = $this->decode($response->getContent());

        $this->assertSame(['extra' => 'info'], $body['meta']);
    }

    public function testSuccessWithCustomStatusCode(): void
    {
        $response = ApiResponse::success(['id' => 1], null, 201);
        $this->assertSame(201, $response->getStatusCode());
    }

    public function testErrorEnvelope(): void
    {
        $response = ApiResponse::error(404, 'Not found');

        $this->assertSame(404, $response->getStatusCode());

        $body = $this->decode($response->getContent());

        $this->assertNull($body['data']);
        $this->assertNull($body['meta']);
        $this->assertSame(404, $body['error']['code']);
        $this->assertSame('Not found', $body['error']['message']);
        $this->assertSame([], $body['error']['details']);
    }

    public function testErrorWithDetails(): void
    {
        $response = ApiResponse::error(400, 'Validation failed', ['title is required', 'date is invalid']);
        $body = $this->decode($response->getContent());

        $this->assertSame(['title is required', 'date is invalid'], $body['error']['details']);
    }

    public function testPaginatedEnvelope(): void
    {
        $items = [['id' => 1], ['id' => 2]];
        $response = ApiResponse::paginated($items, 50, 1, 20);

        $this->assertSame(200, $response->getStatusCode());

        $body = $this->decode($response->getContent());

        $this->assertCount(2, $body['data']);
        $this->assertSame(50, $body['meta']['total']);
        $this->assertSame(1, $body['meta']['page']);
        $this->assertSame(20, $body['meta']['limit']);
        $this->assertNull($body['error']);
    }

    public function testResponseContentTypeIsJson(): void
    {
        $response = ApiResponse::success(['ok' => true]);
        $this->assertSame('application/json', $response->headers->get('Content-Type'));
    }

    public function testNoContentResponse(): void
    {
        $response = ApiResponse::noContent();
        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('', $response->getContent());
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string|false $content): array
    {
        $this->assertIsString($content);
        $decoded = json_decode($content, true);
        $this->assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
