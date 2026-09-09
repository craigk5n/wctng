<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Tests\Functional\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The guard paths of the LDAP admin endpoints.
 *
 * Deliberately stops short of the connection attempt: with a host configured
 * the test endpoint dials it for real, which is not something a test suite
 * should do. What is covered here is everything that decides whether it gets
 * that far -- and that the controller is wired at all, since it had no test of
 * any kind before its LDAP calls moved behind the adapter.
 */
final class LdapConfigControllerTest extends WebTestCase
{
    use ApiTestTrait;

    protected function tearDown(): void
    {
        $this->cleanupTestData();
        parent::tearDown();
    }

    public function testTestConnectionRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/v2/admin/ldap-config/test');

        self::assertContains($client->getResponse()->getStatusCode(), [401, 403]);
    }

    public function testTestConnectionReportsWhenNoHostIsConfigured(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        // LDAP is off by default, so the host is empty and the endpoint must
        // say so rather than trying to connect to nothing.
        $client->request('POST', '/api/v2/admin/ldap-config/test', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        self::assertResponseStatusCodeSame(400);

        $body = $this->decodeResponse($client);
        self::assertIsArray($body['error']);
        self::assertStringContainsString('host', (string) $body['error']['message']);
    }

    public function testConfigIsReadableByAnAdmin(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('GET', '/api/v2/admin/ldap-config', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        self::assertResponseIsSuccessful();

        $body = $this->decodeResponse($client);
        self::assertIsArray($body['data']);
        self::assertArrayHasKey('enabled', $body['data']);
    }
}
