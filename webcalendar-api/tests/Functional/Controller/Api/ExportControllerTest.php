<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Tests\Functional\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ExportControllerTest extends WebTestCase
{
    use ApiTestTrait;

    public function testExportReturnsIcsContent(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);
        $this->createTestEvent($client, $token, [
            'title' => 'Export Test Event',
            'start_date' => '20260501',
            'start_time' => '100000',
            'duration' => 60,
        ]);

        $client->request('GET', '/api/v2/export?format=ics&start=20260401&end=20260601', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'text/calendar; charset=utf-8');

        $content = $client->getResponse()->getContent();
        $this->assertIsString($content);
        $this->assertStringContainsString('BEGIN:VCALENDAR', $content);
        $this->assertStringContainsString('BEGIN:VEVENT', $content);
        $this->assertStringContainsString('Export Test Event', $content);
        $this->assertStringContainsString('END:VCALENDAR', $content);
    }

    public function testExportReturnsEmptyCalendarWhenNoEvents(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('GET', '/api/v2/export?format=ics&start=19000101&end=19001231', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseIsSuccessful();
        $content = $client->getResponse()->getContent();
        $this->assertIsString($content);
        $this->assertStringContainsString('BEGIN:VCALENDAR', $content);
        $this->assertStringNotContainsString('BEGIN:VEVENT', $content);
    }

    public function testExportRequiresDateParams(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('GET', '/api/v2/export?format=ics', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testExportRequiresAuth(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v2/export?format=ics&start=20260101&end=20261231');

        $code = $client->getResponse()->getStatusCode();
        $this->assertTrue(\in_array($code, [401, 403], true));
    }

    public function testExportSetsContentDispositionHeader(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('GET', '/api/v2/export?format=ics&start=20260101&end=20261231', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseIsSuccessful();
        $disposition = $client->getResponse()->headers->get('Content-Disposition');
        $this->assertIsString($disposition);
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('.ics', $disposition);
    }
}
