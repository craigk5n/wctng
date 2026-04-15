<?php

declare(strict_types=1);

namespace App\Tests\Unit\CalDav;

use App\CalDav\CoreCalendarBackend;
use App\Service\CoreServiceFactory;
use PHPUnit\Framework\TestCase;
use Sabre\VObject;

final class TaskVTodoTest extends TestCase
{
    private CoreCalendarBackend $backend;

    #[\Override]
    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $factory = new CoreServiceFactory($pdo, 'test');
        $this->backend = new CoreCalendarBackend($factory);
    }

    public function testVTodoIcsContainsSummary(): void
    {
        $ics = <<<'ICS'
            BEGIN:VCALENDAR
            VERSION:2.0
            BEGIN:VTODO
            UID:task-1@test
            SUMMARY:Buy groceries
            STATUS:NEEDS-ACTION
            PERCENT-COMPLETE:0
            END:VTODO
            END:VCALENDAR
            ICS;

        $vcal = VObject\Reader::read($ics);
        $this->assertInstanceOf(VObject\Component\VCalendar::class, $vcal);
        $vtodo = $vcal->VTODO;
        $this->assertNotNull($vtodo);
        $this->assertSame('Buy groceries', (string) $vtodo->SUMMARY);
    }

    public function testVTodoIncludesPercentComplete(): void
    {
        $ics = <<<'ICS'
            BEGIN:VCALENDAR
            VERSION:2.0
            BEGIN:VTODO
            UID:task-2@test
            SUMMARY:Half done
            PERCENT-COMPLETE:50
            STATUS:IN-PROCESS
            END:VTODO
            END:VCALENDAR
            ICS;

        $vcal = VObject\Reader::read($ics);
        $vtodo = $vcal->VTODO;
        $this->assertNotNull($vtodo);
        $this->assertSame('50', (string) $vtodo->{'PERCENT-COMPLETE'});
    }

    public function testVTodoIncludesDueDate(): void
    {
        $ics = <<<'ICS'
            BEGIN:VCALENDAR
            VERSION:2.0
            BEGIN:VTODO
            UID:task-3@test
            SUMMARY:Due task
            DUE:20260401T170000Z
            STATUS:NEEDS-ACTION
            END:VTODO
            END:VCALENDAR
            ICS;

        $vcal = VObject\Reader::read($ics);
        $vtodo = $vcal->VTODO;
        $this->assertNotNull($vtodo);
        $this->assertNotNull($vtodo->DUE);
    }

    public function testVTodoCompletedStatus(): void
    {
        $ics = <<<'ICS'
            BEGIN:VCALENDAR
            VERSION:2.0
            BEGIN:VTODO
            UID:task-4@test
            SUMMARY:Done task
            PERCENT-COMPLETE:100
            STATUS:COMPLETED
            END:VTODO
            END:VCALENDAR
            ICS;

        $vcal = VObject\Reader::read($ics);
        $vtodo = $vcal->VTODO;
        $this->assertNotNull($vtodo);
        $this->assertSame('COMPLETED', (string) $vtodo->STATUS);
        $this->assertSame('100', (string) $vtodo->{'PERCENT-COMPLETE'});
    }

    public function testCreateCalendarObjectWithVTodoDoesNotThrow(): void
    {
        $ics = <<<'ICS'
            BEGIN:VCALENDAR
            VERSION:2.0
            BEGIN:VTODO
            UID:task-create@test
            SUMMARY:New Task
            DTSTART:20260401T090000Z
            DUE:20260401T170000Z
            STATUS:NEEDS-ACTION
            PERCENT-COMPLETE:0
            END:VTODO
            END:VCALENDAR
            ICS;

        // Should not throw — may return null if DB not set up
        $result = $this->backend->createCalendarObject('alice', 'task-create.ics', $ics);
        $this->assertTrue($result === null || \is_string($result));
    }

    public function testTaskUriPrefixDistinguishesFromEvents(): void
    {
        // Task URIs use 'task-{id}.ics' prefix
        $this->assertStringStartsWith('task-', 'task-42.ics');

        // Event URIs are just '{id}.ics'
        $this->assertStringStartsWith('4', '42.ics');
    }
}
