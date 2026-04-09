<?php

declare(strict_types=1);

namespace App\Tests\Unit\CalDav;

use App\CalDav\CoreCalendarBackend;
use App\Service\CalDavSyncTokenRepository;
use App\Service\CoreServiceFactory;
use PHPUnit\Framework\TestCase;
use Sabre\CalDAV\Backend\SyncSupport;

final class CalendarSyncTest extends TestCase
{
    public function testImplementsSyncSupport(): void
    {
        $this->assertTrue(is_subclass_of(CoreCalendarBackend::class, SyncSupport::class));
    }

    public function testCalendarIncludesSyncToken(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $factory = new CoreServiceFactory($pdo, 'test');
        $backend = new CoreCalendarBackend($factory);

        $calendars = $backend->getCalendarsForUser('principals/alice');
        $this->assertCount(1, $calendars);
        $this->assertArrayHasKey('{DAV:}sync-token', $calendars[0]);
        $this->assertStringStartsWith('sync-', (string) $calendars[0]['{DAV:}sync-token']);
    }

    public function testCalendarIncludesCtag(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $factory = new CoreServiceFactory($pdo, 'test');
        $backend = new CoreCalendarBackend($factory);

        $calendars = $backend->getCalendarsForUser('principals/alice');
        $this->assertArrayHasKey('{http://calendarserver.org/ns/}getctag', $calendars[0]);
    }

    public function testGetChangesReturnsEmptyWhenTokenMatches(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $factory = new CoreServiceFactory($pdo, 'test');
        $backend = new CoreCalendarBackend($factory);

        $calendars = $backend->getCalendarsForUser('principals/alice');
        $currentToken = (string) $calendars[0]['{DAV:}sync-token'];

        $changes = $backend->getChangesForCalendar('alice', $currentToken, 1);

        $this->assertSame($currentToken, $changes['syncToken']);
        $this->assertEmpty($changes['added']);
        $this->assertEmpty($changes['modified']);
        $this->assertEmpty($changes['deleted']);
    }

    public function testGetChangesReturnsAllOnInitialSync(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $factory = new CoreServiceFactory($pdo, 'test');
        $backend = new CoreCalendarBackend($factory);

        // Initial sync with null token — returns all objects as added
        $changes = $backend->getChangesForCalendar('alice', null, 1);

        $this->assertArrayHasKey('syncToken', $changes);
        $this->assertArrayHasKey('added', $changes);
        $this->assertIsArray($changes['added']);
    }

    public function testSyncTokenRespectsBumpOverride(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $factory = new CoreServiceFactory($pdo, 'test');
        $backend = new CoreCalendarBackend($factory);

        // Bump alice's override to a known huge value, simulating a purge.
        $repo = new CalDavSyncTokenRepository($pdo);
        $repo->bumpForUsers(['alice']);
        $override = $repo->getOverride('alice');
        $this->assertNotNull($override);

        $token = (string) $backend->getCalendarsForUser('principals/alice')[0]['{DAV:}sync-token'];
        $this->assertStringStartsWith('sync-', $token);
        $tail = (int) substr($token, 5);
        // The backend returns max(computed, override). The computed value
        // against an empty DB is 0, so the override must win.
        $this->assertGreaterThanOrEqual($override, $tail);
    }

    public function testGetChangesReturnsAllOnTokenMismatch(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $factory = new CoreServiceFactory($pdo, 'test');
        $backend = new CoreCalendarBackend($factory);

        $changes = $backend->getChangesForCalendar('alice', 'sync-old-token', 1);

        $this->assertArrayHasKey('syncToken', $changes);
        // Token mismatch triggers full re-sync
        $this->assertNotSame('sync-old-token', $changes['syncToken']);
    }
}
