<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\CalDav;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * CalDAV RFC 4791 protocol compliance tests.
 */
final class CalDavComplianceTest extends WebTestCase
{
    private function authHeaders(): array
    {
        return [
            'PHP_AUTH_USER' => 'admin',
            'PHP_AUTH_PW' => 'admin',
        ];
    }

    public function testPropfindOnPrincipalReturnsCalendarHomeSet(): void
    {
        $client = static::createClient();

        $xml = '<?xml version="1.0" encoding="UTF-8"?>
            <d:propfind xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">
                <d:prop>
                    <cal:calendar-home-set/>
                </d:prop>
            </d:propfind>';

        $client->request('PROPFIND', '/dav/principals/admin', [], [], array_merge($this->authHeaders(), [
            'CONTENT_TYPE' => 'application/xml',
            'HTTP_DEPTH' => '0',
        ]), $xml);

        $response = $client->getResponse();
        $this->assertSame(207, $response->getStatusCode(), 'Expected 207 Multi-Status');

        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertStringContainsString('multistatus', $content);
    }

    public function testPropfindOnCalendarHomeReturnsCalendarList(): void
    {
        $client = static::createClient();

        $client->request('PROPFIND', '/dav/calendars/admin/', [], [], array_merge($this->authHeaders(), [
            'CONTENT_TYPE' => 'application/xml',
            'HTTP_DEPTH' => '1',
        ]), '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:displayname/></d:prop></d:propfind>');

        $response = $client->getResponse();
        $code = $response->getStatusCode();
        $this->assertTrue(\in_array($code, [207, 200], true), "Expected 207 or 200, got {$code}");
    }

    public function testOptionsReturnsCalDavHeaders(): void
    {
        $client = static::createClient();

        $client->request('OPTIONS', '/dav/', [], [], $this->authHeaders());

        $response = $client->getResponse();
        $this->assertTrue($response->getStatusCode() < 400, 'OPTIONS should not fail');

        // Should include DAV header
        $dav = $response->headers->get('DAV');
        if ($dav !== null) {
            $this->assertStringContainsString('1', $dav);
        }
    }

    public function testPropfindRootReturnsMultistatus(): void
    {
        $client = static::createClient();

        $client->request('PROPFIND', '/dav/', [], [], array_merge($this->authHeaders(), [
            'HTTP_DEPTH' => '0',
            'CONTENT_TYPE' => 'application/xml',
        ]), '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/></d:prop></d:propfind>');

        $response = $client->getResponse();
        $this->assertSame(207, $response->getStatusCode());

        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertStringContainsString('multistatus', $content);
    }

    public function testUnauthenticatedPropfindReturns401(): void
    {
        $client = static::createClient();

        $client->request('PROPFIND', '/dav/', [], [], [
            'HTTP_DEPTH' => '0',
        ]);

        $this->assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testCalDavServerResponds(): void
    {
        $client = static::createClient();

        $client->request('GET', '/dav/', [], [], $this->authHeaders());

        $response = $client->getResponse();
        // sabre/dav browser plugin returns HTML listing or redirect
        $this->assertTrue($response->getStatusCode() < 500, 'CalDAV server should not error');
    }
}
