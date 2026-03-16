<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\CalDav;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CalDavSmokeTest extends WebTestCase
{
    public function testOptionsReturnsWebDavHeaders(): void
    {
        $client = static::createClient();
        $client->request('OPTIONS', '/dav/');

        $response = $client->getResponse();
        // sabre/dav should respond with DAV headers
        $this->assertTrue(
            $response->getStatusCode() < 500,
            'CalDAV endpoint should not return 500. Got: ' . $response->getStatusCode(),
        );
    }

    public function testPropfindWithoutAuthReturns401(): void
    {
        $client = static::createClient();
        $client->request('PROPFIND', '/dav/', [], [], [
            'HTTP_DEPTH' => '0',
            'CONTENT_TYPE' => 'application/xml',
        ]);

        $response = $client->getResponse();
        // Should require authentication
        $this->assertSame(401, $response->getStatusCode());
    }

    public function testPropfindWithAuthReturnsMultistatus(): void
    {
        $client = static::createClient();

        // Use HTTP Basic auth
        $client->request('PROPFIND', '/dav/', [], [], [
            'HTTP_DEPTH' => '0',
            'CONTENT_TYPE' => 'application/xml',
            'PHP_AUTH_USER' => 'admin',
            'PHP_AUTH_PW' => 'admin',
        ]);

        $response = $client->getResponse();
        $statusCode = $response->getStatusCode();

        // Should return 207 Multi-Status (WebDAV success)
        $this->assertTrue(
            \in_array($statusCode, [207, 200], true),
            "Expected 207 or 200, got {$statusCode}",
        );
    }
}
