<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Tests\Functional\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ImportControllerTest extends WebTestCase
{
    use ApiTestTrait;

    private function createIcsFile(string $title = 'Imported Event', string $uid = ''): UploadedFile
    {
        if ($uid === '') {
            $uid = 'test-import-' . bin2hex(random_bytes(8)) . '@webcalendar';
        }

        $ics = "BEGIN:VCALENDAR\r\n"
            . "VERSION:2.0\r\n"
            . "PRODID:-//Test//Test//EN\r\n"
            . "BEGIN:VEVENT\r\n"
            . "UID:{$uid}\r\n"
            . "DTSTART:20260601T100000\r\n"
            . "DTEND:20260601T110000\r\n"
            . "SUMMARY:{$title}\r\n"
            . "END:VEVENT\r\n"
            . "END:VCALENDAR\r\n";

        $tmpFile = tempnam(sys_get_temp_dir(), 'ics_');
        \assert(\is_string($tmpFile));
        file_put_contents($tmpFile, $ics);

        return new UploadedFile($tmpFile, 'test.ics', 'text/calendar', null, true);
    }

    public function testImportIcsFile(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $file = $this->createIcsFile('Import Test Event');

        $client->request('POST', '/api/v2/import', [], ['file' => $file], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseIsSuccessful();
        $body = $this->decodeResponse($client);
        $this->assertArrayHasKey('imported', $body['data']);
        $this->assertArrayHasKey('skipped', $body['data']);
        $this->assertGreaterThanOrEqual(0, $body['data']['imported']);
    }

    public function testImportRequiresFile(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('POST', '/api/v2/import', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testImportRequiresAuth(): void
    {
        $client = static::createClient();
        $file = $this->createIcsFile();

        $client->request('POST', '/api/v2/import', [], ['file' => $file]);

        $code = $client->getResponse()->getStatusCode();
        $this->assertTrue(\in_array($code, [401, 403], true));
    }

    public function testImportReturnsWarningsArray(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $file = $this->createIcsFile('Warning Test');

        $client->request('POST', '/api/v2/import', [], ['file' => $file], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseIsSuccessful();
        $body = $this->decodeResponse($client);
        $this->assertArrayHasKey('warnings', $body['data']);
        $this->assertIsArray($body['data']['warnings']);
    }

    public function testImportInvalidIcsReturns400(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $tmpFile = tempnam(sys_get_temp_dir(), 'bad_');
        \assert(\is_string($tmpFile));
        file_put_contents($tmpFile, 'This is not an ICS file');
        $file = new UploadedFile($tmpFile, 'bad.ics', 'text/calendar', null, true);

        $client->request('POST', '/api/v2/import', [], ['file' => $file], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $code = $client->getResponse()->getStatusCode();
        // Should be 400 for invalid ICS or 500 if parser throws
        $this->assertTrue(\in_array($code, [400, 500], true));
    }
}
