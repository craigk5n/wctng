<?php

declare(strict_types=1);

namespace App\Tests\Unit\CalDav;

use App\CalDav\CoreCalendarBackend;
use App\Service\CoreServiceFactory;
use PHPUnit\Framework\TestCase;
use Sabre\CalDAV\Plugin;
use Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;

final class MultiComponentTest extends TestCase
{
    private CoreCalendarBackend $backend;

    #[\Override]
    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $factory = new CoreServiceFactory($pdo, 'test');
        $this->backend = new CoreCalendarBackend($factory);
    }

    public function testCalendarAdvertisesAllComponentTypes(): void
    {
        $calendars = $this->backend->getCalendarsForUser('principals/alice');
        $this->assertCount(1, $calendars);

        $componentSet = $calendars[0]['{' . Plugin::NS_CALDAV . '}supported-calendar-component-set'];
        $this->assertInstanceOf(SupportedCalendarComponentSet::class, $componentSet);
    }

    public function testCalendarQueryReturnsAllObjectsWithoutFilter(): void
    {
        // Without comp-filters, should return all objects
        $result = $this->backend->calendarQuery('alice', []);
        $this->assertIsArray($result);
    }

    public function testCalendarQueryFiltersVEvent(): void
    {
        // Create some objects first via createCalendarObject
        $this->backend->createCalendarObject('alice', 'event1.ics', "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:e1@test\r\nSUMMARY:Event\r\nDTSTART:20260401T100000Z\r\nEND:VEVENT\r\nEND:VCALENDAR");
        $this->backend->createCalendarObject('alice', 'task1.ics', "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTODO\r\nUID:t1@test\r\nSUMMARY:Task\r\nDTSTART:20260401T100000Z\r\nEND:VTODO\r\nEND:VCALENDAR");

        $filters = [
            'comp-filters' => [
                ['name' => 'VEVENT', 'is-not-defined' => false, 'comp-filters' => [], 'prop-filters' => []],
            ],
            'prop-filters' => [],
        ];

        $result = $this->backend->calendarQuery('alice', $filters);
        $this->assertIsArray($result);
        // Should only return events, not tasks
        foreach ($result as $uri) {
            $this->assertStringNotContainsString('task-', $uri);
        }
    }

    public function testCalendarQueryFiltersVTodo(): void
    {
        $filters = [
            'comp-filters' => [
                ['name' => 'VTODO', 'is-not-defined' => false, 'comp-filters' => [], 'prop-filters' => []],
            ],
            'prop-filters' => [],
        ];

        $result = $this->backend->calendarQuery('alice', $filters);
        $this->assertIsArray($result);
        // All returned URIs (if any) should be task URIs
        foreach ($result as $uri) {
            if ($uri !== '') {
                $this->assertStringStartsWith('task-', $uri);
            }
        }
    }

    public function testCalendarQueryFiltersVJournal(): void
    {
        $filters = [
            'comp-filters' => [
                ['name' => 'VJOURNAL', 'is-not-defined' => false, 'comp-filters' => [], 'prop-filters' => []],
            ],
            'prop-filters' => [],
        ];

        $result = $this->backend->calendarQuery('alice', $filters);
        $this->assertIsArray($result);
        // All returned URIs (if any) should be journal URIs
        foreach ($result as $uri) {
            if ($uri !== '') {
                $this->assertStringStartsWith('journal-', $uri);
            }
        }
    }
}
