<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Tests\Functional\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LayerControllerTest extends WebTestCase
{
    use ApiTestTrait;

    public function testListLayersEmpty(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('GET', '/api/v2/layers', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseIsSuccessful();
        $body = $this->decodeResponse($client);
        $this->assertIsArray($body['data']);
    }

    public function testCreateLayer(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        // Create a second user to use as the layer source
        $login = 'layer_user_' . bin2hex(random_bytes(3));
        $client->request('POST', '/api/v2/users', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode([
            'login' => $login,
            'password' => 'Pass123!',
            'email' => $login . '@example.com',
        ]));
        $this->assertResponseStatusCodeSame(201);

        $client->request('POST', '/api/v2/layers', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode([
            'source_user' => $login,
            'color' => '#FF5733',
        ]));

        $this->assertResponseStatusCodeSame(201);
        $body = $this->decodeResponse($client);
        $this->assertSame($login, $body['data']['source_user']);
        $this->assertSame('#FF5733', $body['data']['color']);
    }

    public function testCreateLayerRequiresSourceUser(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('POST', '/api/v2/layers', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode(['color' => '#FF5733']));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testListLayersAfterCreate(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $login = 'layer_list_' . bin2hex(random_bytes(3));
        $client->request('POST', '/api/v2/users', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode([
            'login' => $login,
            'password' => 'Pass123!',
            'email' => $login . '@example.com',
        ]));

        $client->request('POST', '/api/v2/layers', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode([
            'source_user' => $login,
            'color' => '#00FF00',
        ]));
        $this->assertResponseStatusCodeSame(201);

        $client->request('GET', '/api/v2/layers', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $body = $this->decodeResponse($client);
        $found = false;
        foreach ($body['data'] as $layer) {
            if ($layer['source_user'] === $login) {
                $found = true;
                $this->assertSame('#00FF00', $layer['color']);
            }
        }
        $this->assertTrue($found, "Expected layer for user {$login} in list");
    }

    public function testUpdateLayerColor(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $login = 'layer_upd_' . bin2hex(random_bytes(3));
        $client->request('POST', '/api/v2/users', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode([
            'login' => $login,
            'password' => 'Pass123!',
            'email' => $login . '@example.com',
        ]));

        $client->request('POST', '/api/v2/layers', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode([
            'source_user' => $login,
            'color' => '#FF0000',
        ]));
        $createBody = $this->decodeResponse($client);
        $layerId = $createBody['data']['id'];

        $client->request('PUT', "/api/v2/layers/{$layerId}", [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode(['color' => '#0000FF']));

        $this->assertResponseIsSuccessful();
        $body = $this->decodeResponse($client);
        $this->assertSame('#0000FF', $body['data']['color']);
    }

    public function testDeleteLayer(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $login = 'layer_del_' . bin2hex(random_bytes(3));
        $client->request('POST', '/api/v2/users', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode([
            'login' => $login,
            'password' => 'Pass123!',
            'email' => $login . '@example.com',
        ]));

        $client->request('POST', '/api/v2/layers', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode([
            'source_user' => $login,
            'color' => '#FF0000',
        ]));
        $createBody = $this->decodeResponse($client);
        $layerId = $createBody['data']['id'];

        $client->request('DELETE', "/api/v2/layers/{$layerId}", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        $this->assertResponseStatusCodeSame(204);
    }

    public function testLayerRequiresAuth(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v2/layers');

        $code = $client->getResponse()->getStatusCode();
        $this->assertTrue(\in_array($code, [401, 403], true));
    }

    public function testEventsIncludeLayeredUserEvents(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        // Create a second user
        $login = 'layer_evt_' . bin2hex(random_bytes(3));
        $client->request('POST', '/api/v2/users', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode([
            'login' => $login,
            'password' => 'Pass123!',
            'email' => $login . '@example.com',
        ]));

        // Login as the second user and create an event
        $token2 = $this->loginAndGetToken($client, $login, 'Pass123!');
        $client->request('POST', '/api/v2/events', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token2,
        ], (string) json_encode([
            'title' => 'Layer Test Event',
            'start_date' => '20260615',
            'start_time' => '100000',
            'duration' => 60,
        ]));
        $this->assertResponseStatusCodeSame(201);

        // Login back as admin and add a layer for this user
        $client->request('POST', '/api/v2/layers', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode([
            'source_user' => $login,
            'color' => '#FF5733',
        ]));
        $this->assertResponseStatusCodeSame(201);

        // Fetch events with layers=1 — should include the layered user's event
        $client->request('GET', '/api/v2/events?start=20260601&end=20260630&layers=1&limit=100', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseIsSuccessful();
        $body = $this->decodeResponse($client);
        $titles = array_map(static fn ($e) => $e['title'], $body['data']);
        $this->assertContains('Layer Test Event', $titles);
    }
}
