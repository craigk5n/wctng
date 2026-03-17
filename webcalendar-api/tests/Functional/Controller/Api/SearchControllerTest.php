<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Tests\Functional\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SearchControllerTest extends WebTestCase
{
    use ApiTestTrait;

    public function testSearchFindsEvent(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $unique = 'SearchUnique' . bin2hex(random_bytes(4));
        $this->createTestEvent($client, $token, [
            'title' => $unique,
            'start_date' => '20260601',
            'start_time' => '100000',
            'duration' => 30,
        ]);

        $client->request('GET', '/api/v2/search?q=' . $unique, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseIsSuccessful();
        $body = $this->decodeResponse($client);
        $this->assertIsArray($body['data']);
        $this->assertNotEmpty($body['data']);
        $this->assertSame($unique, $body['data'][0]['title']);
    }

    public function testSearchReturnsEmptyForNoMatch(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('GET', '/api/v2/search?q=zzzznonexistent999', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseIsSuccessful();
        $body = $this->decodeResponse($client);
        $this->assertSame([], $body['data']);
    }

    public function testSearchRequiresQuery(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('GET', '/api/v2/search', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testSearchRequiresAuth(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v2/search?q=test');

        $code = $client->getResponse()->getStatusCode();
        $this->assertTrue(\in_array($code, [401, 403], true));
    }

    public function testSearchResultHasExpectedFields(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $unique = 'FieldCheck' . bin2hex(random_bytes(4));
        $this->createTestEvent($client, $token, [
            'title' => $unique,
            'start_date' => '20260801',
            'start_time' => '140000',
            'duration' => 60,
        ]);

        $client->request('GET', '/api/v2/search?q=' . $unique, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $body = $this->decodeResponse($client);
        $this->assertNotEmpty($body['data']);

        $result = $body['data'][0];
        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('start_date', $result);
        $this->assertArrayHasKey('type', $result);
        $this->assertArrayHasKey('snippet', $result);
    }

    public function testSearchWithPagination(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('GET', '/api/v2/search?q=test&limit=5&offset=0', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseIsSuccessful();
        $body = $this->decodeResponse($client);
        $this->assertIsArray($body['data']);
        $this->assertArrayHasKey('total', $body['meta']);
    }
}
