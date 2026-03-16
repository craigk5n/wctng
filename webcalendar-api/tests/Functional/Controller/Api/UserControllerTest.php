<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Tests\Functional\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class UserControllerTest extends WebTestCase
{
    use ApiTestTrait;

    public function testListUsersAsAdmin(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('GET', '/api/v2/users', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseIsSuccessful();
        $body = $this->decodeResponse($client);
        $this->assertIsArray($body['data']);
        $this->assertNotEmpty($body['data']);
        $this->assertArrayHasKey('meta', $body);
    }

    public function testListUsersRequiresAuth(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v2/users');

        $code = $client->getResponse()->getStatusCode();
        $this->assertTrue(\in_array($code, [401, 403], true));
    }

    public function testGetOwnProfileAsAdmin(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('GET', '/api/v2/users/admin', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseIsSuccessful();
        $body = $this->decodeResponse($client);
        $this->assertSame('admin', $body['data']['login']);
        $this->assertArrayHasKey('firstname', $body['data']);
        $this->assertTrue($body['data']['is_admin']);
    }

    public function testGetUserExcludesPasswordHash(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('GET', '/api/v2/users/admin', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $body = $this->decodeResponse($client);
        $this->assertArrayNotHasKey('password', $body['data']);
        $this->assertArrayNotHasKey('password_hash', $body['data']);
        $this->assertArrayNotHasKey('passwd', $body['data']);
    }

    public function testGetNonexistentUserReturns404(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('GET', '/api/v2/users/nonexistent_xyz', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testGetUserReturnsJsonContentType(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('GET', '/api/v2/users/admin', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseHeaderSame('Content-Type', 'application/json');
    }

    public function testListUsersResponseHasExpectedFields(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('GET', '/api/v2/users', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $body = $this->decodeResponse($client);
        $this->assertNotEmpty($body['data']);

        $user = $body['data'][0];
        $this->assertArrayHasKey('login', $user);
        $this->assertArrayHasKey('firstname', $user);
        $this->assertArrayHasKey('lastname', $user);
        $this->assertArrayHasKey('email', $user);
        $this->assertArrayHasKey('is_admin', $user);
        $this->assertArrayNotHasKey('password', $user);
    }
}
