<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller\Api;

use App\Controller\Api\CspReportController;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\Request;

final class CspReportControllerTest extends TestCase
{
    public function testLogsLegacyReportUriFormat(): void
    {
        $logger = new InMemoryLogger();
        $controller = new CspReportController($logger);

        $body = json_encode([
            'csp-report' => [
                'document-uri' => 'https://example.com/page?session=abc123',
                'violated-directive' => 'script-src',
                'blocked-uri' => 'https://evil.example/exfil.js?secret=xyz',
            ],
        ], JSON_THROW_ON_ERROR);

        $response = $controller->report($this->request($body, 'application/csp-report'));

        $this->assertSame(204, $response->getStatusCode());
        $this->assertCount(1, $logger->records);
        $this->assertSame('warning', $logger->records[0]['level']);
        $this->assertSame('CSP violation reported', $logger->records[0]['message']);

        /** @var array{csp: array<string, mixed>} $context */
        $context = $logger->records[0]['context'];
        // Query strings stripped from URL-shaped fields
        $this->assertSame('https://example.com/page', $context['csp']['document-uri']);
        $this->assertSame('https://evil.example/exfil.js', $context['csp']['blocked-uri']);
        $this->assertSame('script-src', $context['csp']['violated-directive']);
    }

    public function testLogsReportingApiBatchedFormat(): void
    {
        $logger = new InMemoryLogger();
        $controller = new CspReportController($logger);

        $body = json_encode([
            [
                'type' => 'csp-violation',
                'body' => [
                    'documentURL' => 'https://example.com/a',
                    'blockedURL' => 'inline',
                    'effectiveDirective' => 'script-src-elem',
                ],
            ],
            ['type' => 'deprecation', 'body' => ['id' => 'ignored']],
            [
                'type' => 'csp-violation',
                'body' => [
                    'documentURL' => 'https://example.com/b',
                    'effectiveDirective' => 'img-src',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $controller->report($this->request($body, 'application/reports+json'));

        $this->assertCount(2, $logger->records, 'only the two csp-violation entries should be logged');
    }

    public function testMalformedBodyIsLoggedButDoesNotLeakRawContent(): void
    {
        $logger = new InMemoryLogger();
        $controller = new CspReportController($logger);

        $response = $controller->report($this->request('not json at all', 'application/csp-report'));

        $this->assertSame(204, $response->getStatusCode());
        $this->assertCount(1, $logger->records);
        $this->assertSame('CSP report with non-JSON body', $logger->records[0]['message']);
        $this->assertArrayNotHasKey('body', $logger->records[0]['context'], 'raw body must not be logged');
    }

    public function testEmptyBodyIsNoOp(): void
    {
        $logger = new InMemoryLogger();
        $controller = new CspReportController($logger);

        $response = $controller->report($this->request('', 'application/csp-report'));

        $this->assertSame(204, $response->getStatusCode());
        $this->assertCount(0, $logger->records);
    }

    private function request(string $body, string $contentType): Request
    {
        $r = Request::create('/api/v2/csp-report', 'POST', [], [], [], [], $body);
        $r->headers->set('Content-Type', $contentType);
        return $r;
    }
}

/**
 * Minimal PSR-3 logger that captures records into an array so tests can
 * assert on level, message, and structured context without mocking.
 *
 * @internal
 */
final class InMemoryLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param mixed                $level
     * @param string|\Stringable   $message
     * @param array<array-key, mixed> $context
     */
    #[\Override]
    public function log($level, $message, array $context = []): void
    {
        /** @var array<string, mixed> $stringContext */
        $stringContext = $context;
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $stringContext,
        ];
    }
}
