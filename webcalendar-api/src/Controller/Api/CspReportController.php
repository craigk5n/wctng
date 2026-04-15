<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Response\ApiResponse;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Collects Content-Security-Policy violation reports from browsers so
 * we can detect breakage caused by the enforced policy without waiting
 * for user bug reports. Accepts both the legacy `report-uri` format
 * (`application/csp-report`) and the newer `report-to` / Reporting API
 * format (`application/reports+json`).
 *
 * Violations are logged at WARNING level with structured context so
 * dashboards and alerting can watch the signal during the soft-rollout
 * window when we emit both CSP and CSP-Report-Only.
 */
final class CspReportController
{
    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    #[Route('/api/v2/csp-report', name: 'api_csp_report', methods: ['POST'])]
    public function report(Request $request): Response
    {
        $body = $request->getContent();
        if ($body === '') {
            return ApiResponse::noContent();
        }

        $decoded = json_decode($body, true);
        if (!\is_array($decoded)) {
            // Malformed — don't echo it back, don't log the raw body
            // (it may include URLs with session tokens in query strings).
            $this->logger->warning('CSP report with non-JSON body', [
                'content_type' => $request->headers->get('Content-Type'),
                'bytes' => \strlen($body),
            ]);
            return ApiResponse::noContent();
        }

        foreach ($this->extractReports($decoded) as $entry) {
            $this->logger->warning('CSP violation reported', [
                'csp' => $this->redactSensitiveFields($entry),
                'user_agent' => $request->headers->get('User-Agent'),
            ]);
        }

        return new JsonResponse(null, 204);
    }

    /**
     * @param array<mixed, mixed> $decoded
     *
     * @return iterable<array<string, mixed>>
     */
    private function extractReports(array $decoded): iterable
    {
        // Legacy report-uri: { "csp-report": { ... } }
        if (isset($decoded['csp-report']) && \is_array($decoded['csp-report'])) {
            /** @var array<string, mixed> $report */
            $report = $decoded['csp-report'];
            yield $report;
            return;
        }

        // Reporting API: an array of { "type": "csp-violation", "body": { ... } }
        if (array_is_list($decoded)) {
            foreach ($decoded as $item) {
                if (!\is_array($item)) {
                    continue;
                }
                if (($item['type'] ?? null) !== 'csp-violation') {
                    continue;
                }
                if (!\is_array($item['body'] ?? null)) {
                    continue;
                }
                /** @var array<string, mixed> $body */
                $body = $item['body'];
                yield $body;
            }
            return;
        }

        // Single Reporting-API entry
        if (($decoded['type'] ?? null) === 'csp-violation' && \is_array($decoded['body'] ?? null)) {
            /** @var array<string, mixed> $body */
            $body = $decoded['body'];
            yield $body;
        }
    }

    /**
     * Strips query strings from URL-shaped fields so session tokens in
     * report URLs don't get logged. Keeps the path, directive, and
     * policy fields intact.
     *
     * @param array<string, mixed> $report
     *
     * @return array<string, mixed>
     */
    private function redactSensitiveFields(array $report): array
    {
        $urlFields = ['document-uri', 'documentURL', 'referrer', 'blocked-uri', 'blockedURL', 'source-file', 'sourceFile'];
        foreach ($urlFields as $key) {
            if (!isset($report[$key]) || !\is_string($report[$key])) {
                continue;
            }
            $report[$key] = $this->stripQueryString($report[$key]);
        }

        return $report;
    }

    private function stripQueryString(string $url): string
    {
        $q = strpos($url, '?');
        if ($q === false) {
            return $url;
        }
        return substr($url, 0, $q);
    }
}
