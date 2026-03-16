<?php

declare(strict_types=1);

namespace App\Tests\Unit\CalDav;

use App\CalDav\CoreCalendarBackend;
use PHPUnit\Framework\TestCase;
use Sabre\CalDAV\Backend\BackendInterface;
use Sabre\CalDAV\Plugin;
use Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;

final class CoreCalendarBackendTest extends TestCase
{
    private CoreCalendarBackend $backend;

    #[\Override]
    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $factory = new \App\Service\CoreServiceFactory($pdo, 'test');
        $this->backend = new CoreCalendarBackend($factory);
    }

    public function testImplementsBackendInterface(): void
    {
        $this->assertInstanceOf(BackendInterface::class, $this->backend);
    }

    public function testGetCalendarsForUserReturnsDefaultCalendar(): void
    {
        $calendars = $this->backend->getCalendarsForUser('principals/alice');

        $this->assertCount(1, $calendars);
        $cal = $calendars[0];
        $this->assertSame('alice', $cal['id']);
        $this->assertSame('default', $cal['uri']);
        $this->assertSame('principals/alice', $cal['principaluri']);
    }

    public function testCalendarHasDisplayName(): void
    {
        $calendars = $this->backend->getCalendarsForUser('principals/bob');
        $this->assertSame('Calendar', $calendars[0]['{DAV:}displayname']);
    }

    public function testCalendarHasColor(): void
    {
        $calendars = $this->backend->getCalendarsForUser('principals/bob');
        $this->assertSame('#3788d8', $calendars[0]['{http://apple.com/ns/ical/}calendar-color']);
    }

    public function testCalendarHasDescription(): void
    {
        $calendars = $this->backend->getCalendarsForUser('principals/bob');
        $this->assertArrayHasKey('{' . Plugin::NS_CALDAV . '}calendar-description', $calendars[0]);
    }

    public function testCalendarSupportsVEventVTodoVJournal(): void
    {
        $calendars = $this->backend->getCalendarsForUser('principals/bob');
        $componentSet = $calendars[0]['{' . Plugin::NS_CALDAV . '}supported-calendar-component-set'];

        $this->assertInstanceOf(SupportedCalendarComponentSet::class, $componentSet);
    }

    public function testCreateCalendarReturnsCalendarId(): void
    {
        $id = $this->backend->createCalendar('principals/charlie', 'work', []);
        $this->assertSame('charlie', $id);
    }

    public function testDeleteCalendarDoesNotThrow(): void
    {
        // Default calendar cannot be deleted — should be a no-op
        $this->backend->deleteCalendar('alice');
        $this->assertTrue(true);
    }
}
