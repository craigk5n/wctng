<?php

declare(strict_types=1);

namespace App\Tests\Integration\CalDav;

use App\Service\CalDavSyncTokenRepository;
use App\Tests\Integration\IntegrationTestCase;

/**
 * Example integration tests using the in-process sabre/dav harness.
 *
 * These exercise the full HTTP + XML + property-handler pipeline of the
 * CalDAV server without booting Symfony, issuing JWTs, or hitting a real
 * database beyond the in-memory SQLite provided by IntegrationTestCase.
 *
 * They serve as both a smoke test of CalDAV wiring and a concrete template
 * for writing more thorough RFC-compliance coverage.
 */
final class CalDavHarnessTest extends IntegrationTestCase
{
    private CalDavServerHarness $harness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->harness = new CalDavServerHarness($this->factory, 'admin');
    }

    public function testPropfindOnRootReturnsMultiStatus(): void
    {
        $response = $this->harness->propfind('/dav/', [
            '{DAV:}displayname',
            '{DAV:}resourcetype',
        ]);

        $this->assertSame(207, $response->getStatus(), 'PROPFIND should return 207 Multi-Status');
        $body = $response->getBodyAsString();
        $this->assertStringContainsString('multistatus', $body);
    }

    public function testPropfindOnCalendarHomeLists(): void
    {
        $response = $this->harness->propfind('/dav/calendars/admin/', [
            '{DAV:}displayname',
            '{DAV:}resourcetype',
        ], depth: 1);

        $this->assertSame(207, $response->getStatus());
        $body = $response->getBodyAsString();
        // The user's default calendar should appear in the listing
        $this->assertStringContainsString('calendars/admin', $body);
    }

    public function testPropfindCalendarIncludesSyncToken(): void
    {
        $response = $this->harness->propfind('/dav/calendars/admin/default/', [
            '{DAV:}sync-token',
        ]);

        $this->assertSame(207, $response->getStatus());
        $body = $response->getBodyAsString();
        $this->assertStringContainsString('sync-token', $body);
        $this->assertStringContainsString('sync-', $body);
    }

    public function testSyncCollectionReportReturnsToken(): void
    {
        $response = $this->harness->syncCollection('/dav/calendars/admin/default/');

        // Either 207 (normal) or a reasonable error depending on empty state
        $this->assertContains(
            $response->getStatus(),
            [200, 207],
            'sync-collection REPORT should return success. Got: ' . $response->getBodyAsString(),
        );
        $body = $response->getBodyAsString();
        $this->assertStringContainsString('sync-token', $body);
    }

    public function testSyncTokenAdvancesAfterRepositoryBump(): void
    {
        // End-to-end verification of the DEL-S1 sync-token bump: before
        // bumping, read the token over the full HTTP pipeline; bump via
        // the repository (simulating what PurgeService does); re-read the
        // token and assert it has moved forward.
        $before = $this->extractSyncTokenFromPropfind();

        $repo = new CalDavSyncTokenRepository($this->pdo);
        $repo->bumpForUsers(['admin']);

        $after = $this->extractSyncTokenFromPropfind();

        $this->assertNotSame(
            $before,
            $after,
            'sync-token must change after PurgeService-style bump so CalDAV clients re-sync',
        );
    }

    private function extractSyncTokenFromPropfind(): string
    {
        $response = $this->harness->propfind('/dav/calendars/admin/default/', [
            '{DAV:}sync-token',
        ]);
        $this->assertSame(207, $response->getStatus());
        $body = $response->getBodyAsString();

        // Pull the <sync-token>...</sync-token> content. The XML uses
        // varying namespace prefixes depending on sabre's output, so
        // match liberally.
        if (preg_match('#<[^:>]*:?sync-token[^>]*>([^<]+)</[^:>]*:?sync-token>#', $body, $m) !== 1) {
            $this->fail('Could not find sync-token in response body: ' . $body);
        }
        return $m[1];
    }
}
