<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Tests\Functional\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CategoryControllerTest extends WebTestCase
{
    use ApiTestTrait;

    private function createCategory(KernelBrowser $client, string $token, string $name, ?string $color = '#0000FF', bool $isGlobal = false): int
    {
        $client->request('POST', '/api/v2/categories', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode([
            'name' => $name,
            'color' => $color,
            'is_global' => $isGlobal,
        ]));

        $body = $this->decodeResponse($client);

        /** @var int */
        return $body['data']['id'];
    }

    // --- List ---

    public function testListCategories(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);
        $this->createCategory($client, $token, 'ListTest_' . bin2hex(random_bytes(3)));

        $client->request('GET', '/api/v2/categories', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseIsSuccessful();
        $body = $this->decodeResponse($client);
        $this->assertIsArray($body['data']);
        $this->assertNotEmpty($body['data']);
    }

    public function testListRequiresAuth(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v2/categories');

        $code = $client->getResponse()->getStatusCode();
        $this->assertTrue(\in_array($code, [401, 403], true));
    }

    public function testListCategoryHasExpectedFields(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);
        $this->createCategory($client, $token, 'FieldTest_' . bin2hex(random_bytes(3)), '#FF0000');

        $client->request('GET', '/api/v2/categories', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $body = $this->decodeResponse($client);
        $cat = $body['data'][0];
        $this->assertArrayHasKey('id', $cat);
        $this->assertArrayHasKey('name', $cat);
        $this->assertArrayHasKey('color', $cat);
        $this->assertArrayHasKey('is_global', $cat);
        $this->assertArrayHasKey('owner', $cat);
    }

    // --- Get Single ---

    public function testGetCategory(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);
        $catId = $this->createCategory($client, $token, 'GetTest_' . bin2hex(random_bytes(3)), '#00FF00');

        $client->request('GET', "/api/v2/categories/{$catId}", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseIsSuccessful();
        $body = $this->decodeResponse($client);
        $this->assertSame($catId, $body['data']['id']);
        $this->assertStringContainsString('GetTest_', $body['data']['name']);
        $this->assertSame('#00FF00', $body['data']['color']);
    }

    public function testGetNonexistentCategoryReturns404(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('GET', '/api/v2/categories/999999', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    // --- Create ---

    public function testCreateCategory(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $name = 'CreateTest_' . bin2hex(random_bytes(3));
        $client->request('POST', '/api/v2/categories', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode([
            'name' => $name,
            'color' => '#ABCDEF',
        ]));

        $this->assertResponseStatusCodeSame(201);
        $body = $this->decodeResponse($client);
        $this->assertSame($name, $body['data']['name']);
        $this->assertSame('#ABCDEF', $body['data']['color']);
        $this->assertGreaterThan(0, $body['data']['id']);
    }

    public function testCreateWithoutNameReturns400(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('POST', '/api/v2/categories', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode(['color' => '#FF0000']));

        $this->assertResponseStatusCodeSame(400);
    }

    // --- Update ---

    public function testUpdateCategory(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);
        $catId = $this->createCategory($client, $token, 'UpdateBefore_' . bin2hex(random_bytes(3)));

        $client->request('PUT', "/api/v2/categories/{$catId}", [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode(['name' => 'UpdatedName', 'color' => '#111111']));

        $this->assertResponseIsSuccessful();
        $body = $this->decodeResponse($client);
        $this->assertSame('UpdatedName', $body['data']['name']);
        $this->assertSame('#111111', $body['data']['color']);
    }

    public function testUpdateNonexistentReturns404(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('PUT', '/api/v2/categories/999999', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode(['name' => 'X']));

        $this->assertResponseStatusCodeSame(404);
    }

    // --- Delete ---

    public function testDeleteCategory(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);
        $catId = $this->createCategory($client, $token, 'DeleteTest_' . bin2hex(random_bytes(3)));

        $client->request('DELETE', "/api/v2/categories/{$catId}", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseStatusCodeSame(204);

        // Verify gone
        $client->request('GET', "/api/v2/categories/{$catId}", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testDeleteNonexistentReturns404(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('DELETE', '/api/v2/categories/999999', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseStatusCodeSame(404);
    }
}
