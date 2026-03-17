<?php

declare(strict_types=1);

namespace App\Tests\Unit\CalDav;

use App\Service\ValarmHelper;
use PHPUnit\Framework\TestCase;
use Sabre\VObject;
use WebCalendar\Core\Domain\Entity\Reminder;

final class ValarmSupportTest extends TestCase
{
    public function testAddValarmToVevent(): void
    {
        $helper = new ValarmHelper();

        $vcalendar = new VObject\Component\VCalendar();
        $vevent = $vcalendar->add('VEVENT', [
            'UID' => 'test@example.com',
            'SUMMARY' => 'Test Event',
        ]);
        $vevent->add('DTSTART', new \DateTimeImmutable('2026-04-01 10:00:00'));

        // 15 minutes before, DISPLAY action
        $reminder = new Reminder(
            eventId: 1,
            offset: 15,
            related: 'S',
            before: 'Y',
            action: 'DISPLAY',
        );

        $helper->addValarmToComponent($vevent, $reminder);

        $ics = $vcalendar->serialize();
        $this->assertStringContainsString('BEGIN:VALARM', $ics);
        $this->assertStringContainsString('TRIGGER:-PT15M', $ics);
        $this->assertStringContainsString('ACTION:DISPLAY', $ics);
    }

    public function testAddValarmAudioAction(): void
    {
        $helper = new ValarmHelper();

        $vcalendar = new VObject\Component\VCalendar();
        $vevent = $vcalendar->add('VEVENT', [
            'UID' => 'test@example.com',
            'SUMMARY' => 'Test',
        ]);
        $vevent->add('DTSTART', new \DateTimeImmutable('2026-04-01 10:00:00'));

        $reminder = new Reminder(
            eventId: 1,
            offset: 30,
            related: 'S',
            before: 'Y',
            action: 'AUDIO',
        );

        $helper->addValarmToComponent($vevent, $reminder);

        $ics = $vcalendar->serialize();
        $this->assertStringContainsString('ACTION:AUDIO', $ics);
        $this->assertStringContainsString('TRIGGER:-PT30M', $ics);
    }

    public function testAddValarmAfterEnd(): void
    {
        $helper = new ValarmHelper();

        $vcalendar = new VObject\Component\VCalendar();
        $vevent = $vcalendar->add('VEVENT', [
            'UID' => 'test@example.com',
            'SUMMARY' => 'Test',
        ]);
        $vevent->add('DTSTART', new \DateTimeImmutable('2026-04-01 10:00:00'));

        // 5 minutes after end (before='N', related='E')
        $reminder = new Reminder(
            eventId: 1,
            offset: 5,
            related: 'E',
            before: 'N',
            action: 'DISPLAY',
        );

        $helper->addValarmToComponent($vevent, $reminder);

        $ics = $vcalendar->serialize();
        $this->assertStringContainsString('TRIGGER;RELATED=END:PT5M', $ics);
    }

    public function testExtractValarmFromVevent(): void
    {
        $helper = new ValarmHelper();

        $ics = <<<ICS
BEGIN:VCALENDAR
BEGIN:VEVENT
UID:test@example.com
SUMMARY:Test
DTSTART:20260401T100000
BEGIN:VALARM
TRIGGER:-PT15M
ACTION:DISPLAY
DESCRIPTION:Reminder
END:VALARM
END:VEVENT
END:VCALENDAR
ICS;

        $vcalendar = VObject\Reader::read($ics);
        /** @var VObject\Component\VCalendar $vcalendar */
        $vevent = $vcalendar->VEVENT;
        $this->assertNotNull($vevent);

        $reminders = $helper->extractReminders($vevent, 42);

        $this->assertCount(1, $reminders);
        $this->assertSame(42, $reminders[0]->eventId());
        $this->assertSame(15, $reminders[0]->offset());
        $this->assertSame('S', $reminders[0]->related());
        $this->assertSame('Y', $reminders[0]->before());
        $this->assertSame('DISPLAY', $reminders[0]->action());
    }

    public function testExtractMultipleValarms(): void
    {
        $helper = new ValarmHelper();

        $ics = <<<ICS
BEGIN:VCALENDAR
BEGIN:VEVENT
UID:test@example.com
SUMMARY:Test
DTSTART:20260401T100000
BEGIN:VALARM
TRIGGER:-PT15M
ACTION:DISPLAY
DESCRIPTION:15 min
END:VALARM
BEGIN:VALARM
TRIGGER:-PT1H
ACTION:AUDIO
END:VALARM
END:VEVENT
END:VCALENDAR
ICS;

        $vcalendar = VObject\Reader::read($ics);
        /** @var VObject\Component\VCalendar $vcalendar */
        $vevent = $vcalendar->VEVENT;

        $reminders = $helper->extractReminders($vevent, 1);
        $this->assertCount(2, $reminders);
        $this->assertSame(15, $reminders[0]->offset());
        $this->assertSame(60, $reminders[1]->offset());
    }

    public function testExtractNoValarm(): void
    {
        $helper = new ValarmHelper();

        $ics = <<<ICS
BEGIN:VCALENDAR
BEGIN:VEVENT
UID:test@example.com
SUMMARY:Test
DTSTART:20260401T100000
END:VEVENT
END:VCALENDAR
ICS;

        $vcalendar = VObject\Reader::read($ics);
        /** @var VObject\Component\VCalendar $vcalendar */
        $vevent = $vcalendar->VEVENT;

        $reminders = $helper->extractReminders($vevent, 1);
        $this->assertCount(0, $reminders);
    }

    public function testDefaultAlarmFromPreference(): void
    {
        $helper = new ValarmHelper();

        $reminder = $helper->createFromPreference(42, '15');
        $this->assertNotNull($reminder);
        $this->assertSame(42, $reminder->eventId());
        $this->assertSame(15, $reminder->offset());
        $this->assertSame('DISPLAY', $reminder->action());
    }

    public function testDefaultAlarmDisabled(): void
    {
        $helper = new ValarmHelper();

        $reminder = $helper->createFromPreference(42, '0');
        $this->assertNull($reminder);

        $reminder = $helper->createFromPreference(42, '');
        $this->assertNull($reminder);
    }
}
