<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Tests\Functional\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class UserPreferencesTest extends WebTestCase
{
    use ApiTestTrait;

    #[\Override]
    protected function tearDown(): void
    {
        $this->cleanupTestData();
        parent::tearDown();
    }

    public function testGetPreferences(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('GET', '/api/v2/users/admin/preferences', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseIsSuccessful();
        $body = $this->decodeResponse($client);
        $this->assertIsArray($body['data']);
    }

    public function testSetAndGetPreference(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('PUT', '/api/v2/users/admin/preferences', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode([
            'STARTVIEW' => 'timeGridWeek',
            'TIMEZONE' => 'America/New_York',
        ]));

        $this->assertResponseIsSuccessful();

        // Verify saved
        $client->request('GET', '/api/v2/users/admin/preferences', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $body = $this->decodeResponse($client);
        $prefs = $body['data'];

        // Find our preferences
        $startView = null;
        $timezone = null;
        foreach ($prefs as $p) {
            if ($p['key'] === 'STARTVIEW') {
                $startView = $p['value'];
            }
            if ($p['key'] === 'TIMEZONE') {
                $timezone = $p['value'];
            }
        }

        $this->assertSame('timeGridWeek', $startView);
        $this->assertSame('America/New_York', $timezone);
    }

    public function testPreferencesRequiresAuth(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v2/users/admin/preferences');

        $code = $client->getResponse()->getStatusCode();
        $this->assertTrue(\in_array($code, [401, 403], true));
    }
}
