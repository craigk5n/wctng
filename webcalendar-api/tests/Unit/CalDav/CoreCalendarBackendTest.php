<?php

declare(strict_types=1);

namespace App\Tests\Unit\CalDav;

use App\CalDav\CoreCalendarBackend;
use App\Service\TenantAwarePdoProvider;
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
        $this->backend = new CoreCalendarBackend(
            new TenantAwarePdoProvider($factory->getPdo()),
            $factory->getUserService(),
            $factory->getEventService(),
            $factory->getTaskService(),
            $factory->getJournalService(),
            $factory->getEventRepository(),
            $factory->getReminderRepository(),
        );
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

    public function testCreateCalendarRejectsAdditionalCalendars(): void
    {
        // The backend exposes a single default calendar per user and
        // refuses MKCALENDAR requests for new calendar collections.
        $this->expectException(\Sabre\DAV\Exception\MethodNotAllowed::class);
        $this->backend->createCalendar('principals/charlie', 'work', []);
    }

    public function testDeleteCalendarIsForbidden(): void
    {
        // Calendars cannot be deleted via CalDAV — prevents data-loss
        // from a misdirected DELETE on the collection URI.
        $this->expectException(\Sabre\DAV\Exception\Forbidden::class);
        $this->backend->deleteCalendar('alice');
    }
}
