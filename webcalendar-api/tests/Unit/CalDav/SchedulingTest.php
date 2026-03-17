<?php

declare(strict_types=1);

namespace App\Tests\Unit\CalDav;

use App\CalDav\CoreCalendarBackend;
use App\Service\CoreServiceFactory;
use PHPUnit\Framework\TestCase;
use Sabre\CalDAV\Backend\SchedulingSupport;

final class SchedulingTest extends TestCase
{
    private CoreCalendarBackend $backend;

    #[\Override]
    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $factory = new CoreServiceFactory($pdo, 'test');
        $this->backend = new CoreCalendarBackend($factory);
    }

    public function testImplementsSchedulingSupport(): void
    {
        $this->assertInstanceOf(SchedulingSupport::class, $this->backend);
    }

    public function testGetSchedulingObjectsReturnsEmpty(): void
    {
        $objects = $this->backend->getSchedulingObjects('principals/alice');
        $this->assertIsArray($objects);
        $this->assertEmpty($objects);
    }

    public function testCreateAndGetSchedulingObject(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nBEGIN:VFREEBUSY\r\nUID:fb1\r\nEND:VFREEBUSY\r\nEND:VCALENDAR";

        $this->backend->createSchedulingObject('principals/alice', 'freebusy1.ics', $ics);

        $objects = $this->backend->getSchedulingObjects('principals/alice');
        $this->assertCount(1, $objects);
        $this->assertSame('freebusy1.ics', $objects[0]['uri']);

        $single = $this->backend->getSchedulingObject('principals/alice', 'freebusy1.ics');
        $this->assertNotNull($single);
        $this->assertSame('freebusy1.ics', $single['uri']);
        $this->assertSame($ics, $single['calendardata']);
    }

    public function testDeleteSchedulingObject(): void
    {
        $this->backend->createSchedulingObject('principals/alice', 'fb1.ics', 'data');
        $this->assertCount(1, $this->backend->getSchedulingObjects('principals/alice'));

        $this->backend->deleteSchedulingObject('principals/alice', 'fb1.ics');
        $this->assertEmpty($this->backend->getSchedulingObjects('principals/alice'));
    }

    public function testGetSchedulingObjectReturnsNullForMissing(): void
    {
        $this->assertNull($this->backend->getSchedulingObject('principals/alice', 'nonexistent.ics'));
    }

    public function testSchedulingObjectsArePerPrincipal(): void
    {
        $this->backend->createSchedulingObject('principals/alice', 'fb1.ics', 'alice-data');
        $this->backend->createSchedulingObject('principals/bob', 'fb2.ics', 'bob-data');

        $this->assertCount(1, $this->backend->getSchedulingObjects('principals/alice'));
        $this->assertCount(1, $this->backend->getSchedulingObjects('principals/bob'));
    }
}
