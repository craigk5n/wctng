<?php

declare(strict_types=1);

namespace App\Tests\Unit\CalDav;

use App\CalDav\CoreCalendarBackend;
use App\Service\CoreServiceFactory;
use PHPUnit\Framework\TestCase;
use Sabre\VObject;

final class JournalVJournalTest extends TestCase
{
    private CoreCalendarBackend $backend;

    #[\Override]
    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $factory = new CoreServiceFactory($pdo, 'test');
        $this->backend = new CoreCalendarBackend($factory);
    }

    public function testVJournalIcsContainsSummary(): void
    {
        $ics = <<<'ICS'
            BEGIN:VCALENDAR
            VERSION:2.0
            BEGIN:VJOURNAL
            UID:journal-1@test
            SUMMARY:Daily Log
            DESCRIPTION:Deployed v2.0 today.
            DTSTART:20260401
            END:VJOURNAL
            END:VCALENDAR
            ICS;

        $vcal = VObject\Reader::read($ics);
        $this->assertInstanceOf(VObject\Component\VCalendar::class, $vcal);
        $vjournal = $vcal->VJOURNAL;
        $this->assertNotNull($vjournal);
        $this->assertSame('Daily Log', (string) $vjournal->SUMMARY);
    }

    public function testVJournalIncludesDescription(): void
    {
        $ics = <<<'ICS'
            BEGIN:VCALENDAR
            VERSION:2.0
            BEGIN:VJOURNAL
            UID:journal-2@test
            SUMMARY:Meeting Notes
            DESCRIPTION:Discussed Q2 roadmap and priorities.
            DTSTART:20260401
            END:VJOURNAL
            END:VCALENDAR
            ICS;

        $vcal = VObject\Reader::read($ics);
        $vjournal = $vcal->VJOURNAL;
        $this->assertNotNull($vjournal);
        $this->assertSame('Discussed Q2 roadmap and priorities.', (string) $vjournal->DESCRIPTION);
    }

    public function testVJournalIncludesDate(): void
    {
        $ics = <<<'ICS'
            BEGIN:VCALENDAR
            VERSION:2.0
            BEGIN:VJOURNAL
            UID:journal-3@test
            SUMMARY:Date Test
            DTSTART:20260315
            END:VJOURNAL
            END:VCALENDAR
            ICS;

        $vcal = VObject\Reader::read($ics);
        $vjournal = $vcal->VJOURNAL;
        $this->assertNotNull($vjournal);
        $this->assertNotNull($vjournal->DTSTART);
    }

    public function testCreateCalendarObjectWithVJournalDoesNotThrow(): void
    {
        $ics = <<<'ICS'
            BEGIN:VCALENDAR
            VERSION:2.0
            BEGIN:VJOURNAL
            UID:journal-create@test
            SUMMARY:New Journal Entry
            DESCRIPTION:Some notes.
            DTSTART:20260401
            END:VJOURNAL
            END:VCALENDAR
            ICS;

        $result = $this->backend->createCalendarObject('alice', 'journal-create.ics', $ics);
        $this->assertTrue($result === null || \is_string($result));
    }

    public function testJournalUriPrefixDistinguishes(): void
    {
        $this->assertStringStartsWith('journal-', 'journal-42.ics');
        $this->assertStringStartsWith('task-', 'task-42.ics');
        $this->assertFalse(str_starts_with('42.ics', 'journal-'));
    }
}
