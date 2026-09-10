<?php

declare(strict_types=1);

namespace App\Tests\Unit\CalDav;

use App\Service\ValarmHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sabre\VObject;
use WebCalendar\Core\Domain\Entity\Reminder;

/**
 * The TRIGGER round trip.
 *
 * ValarmSupportTest covers the happy paths and stops at "the ICS contains
 * TRIGGER:-PT15M". What is left over is the arithmetic underneath: the day,
 * hour and minute divisors, both halves of the "is there a time part at all"
 * guard, the sign and RELATED handling on the way back in, and the action
 * normalisation at either end -- all of which could be changed without any
 * existing assertion noticing.
 */
final class ValarmTriggerTest extends TestCase
{
    private ValarmHelper $helper;

    #[\Override]
    protected function setUp(): void
    {
        $this->helper = new ValarmHelper();
    }

    private function valarmFor(Reminder $reminder): VObject\Component
    {
        $vcalendar = new VObject\Component\VCalendar();
        $vevent = $vcalendar->add('VEVENT', ['UID' => 'test@example.com']);
        self::assertInstanceOf(VObject\Component::class, $vevent);

        $this->helper->addValarmToComponent($vevent, $reminder);

        $valarm = $vevent->VALARM;
        self::assertInstanceOf(VObject\Component::class, $valarm);

        return $valarm;
    }

    /** @return list<Reminder> */
    private function remindersFrom(string $valarm, int $eventId = 1): array
    {
        $ics = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:test@example.com\r\n"
            . "DTSTART:20260401T100000\r\n" . $valarm . "END:VEVENT\r\nEND:VCALENDAR\r\n";

        $vcalendar = VObject\Reader::read($ics);
        $vevent = $vcalendar->VEVENT;
        self::assertInstanceOf(VObject\Component::class, $vevent);

        return array_values($this->helper->extractReminders($vevent, $eventId));
    }

    private static function alarm(string $trigger, ?string $action = 'DISPLAY'): string
    {
        return "BEGIN:VALARM\r\n" . $trigger . "\r\n"
            . ($action === null ? '' : "ACTION:{$action}\r\n")
            . "END:VALARM\r\n";
    }

    // ---------------------------------------------------------------- writing

    /** @return iterable<string, array{int, string, string}> */
    public static function triggerDurations(): iterable
    {
        yield 'a zero offset is still a duration'   => [0, 'Y', '-PT0M'];
        yield 'minutes only'                        => [15, 'Y', '-PT15M'];
        yield 'a whole hour carries no minute part' => [60, 'Y', '-PT1H'];
        yield 'hours and minutes'                   => [118, 'Y', '-PT1H58M'];
        yield 'a whole day carries no time part'    => [1440, 'Y', '-P1D'];
        yield 'days, hours and minutes'             => [2878, 'Y', '-P1DT23H58M'];
        yield 'a week is written as seven days'     => [10080, 'Y', '-P7D'];
        yield 'after the reference point is unsigned' => [5, 'N', 'PT5M'];
    }

    #[DataProvider('triggerDurations')]
    public function testOffsetBecomesAnIcalendarDuration(int $offset, string $before, string $expected): void
    {
        $valarm = $this->valarmFor(new Reminder(
            eventId: 1,
            offset: $offset,
            related: 'S',
            before: $before,
            action: 'DISPLAY',
        ));

        self::assertSame($expected, (string) $valarm->TRIGGER);
    }

    public function testActionIsNormalisedToUpperCase(): void
    {
        $valarm = $this->valarmFor(new Reminder(
            eventId: 1,
            offset: 15,
            related: 'S',
            before: 'Y',
            action: 'audio',
        ));

        self::assertSame('AUDIO', (string) $valarm->ACTION);
    }

    public function testAnUnknownActionFallsBackToDisplay(): void
    {
        $valarm = $this->valarmFor(new Reminder(
            eventId: 1,
            offset: 15,
            related: 'S',
            before: 'Y',
            action: 'PROCEDURE',
        ));

        self::assertSame('DISPLAY', (string) $valarm->ACTION);
    }

    public function testDisplayAlarmsCarryTheDescriptionTheyAreRequiredToHave(): void
    {
        // RFC 5545: a DISPLAY alarm without a DESCRIPTION is invalid, and some
        // clients drop the whole alarm rather than showing an empty one.
        $valarm = $this->valarmFor(new Reminder(
            eventId: 1,
            offset: 15,
            related: 'S',
            before: 'Y',
            action: 'DISPLAY',
        ));

        self::assertSame('Reminder', (string) $valarm->DESCRIPTION);
    }

    public function testNonDisplayAlarmsGetNoDescription(): void
    {
        $valarm = $this->valarmFor(new Reminder(
            eventId: 1,
            offset: 15,
            related: 'S',
            before: 'Y',
            action: 'AUDIO',
        ));

        self::assertNull($valarm->DESCRIPTION);
    }

    // ---------------------------------------------------------------- reading

    /** @return iterable<string, array{string, int}> */
    public static function parsedDurations(): iterable
    {
        yield 'minutes'            => ['-PT15M', 15];
        yield 'a whole hour'       => ['-PT1H', 60];
        yield 'hours and minutes'  => ['-PT1H30M', 90];
        yield 'a day'              => ['-P1D', 1440];
        yield 'days and hours'     => ['-P1DT2H', 1560];
        yield 'a week'             => ['-P1W', 10080];
        yield 'seconds round up to a minute' => ['-PT45S', 1];
        yield 'zero seconds round up to nothing'  => ['-PT0S', 0];
        yield 'seconds alongside a longer part are dropped' => ['-PT1H30S', 60];
        yield 'no duration at all' => ['-PT0M', 0];

        // RFC 5545 keeps dur-week separate from dur-date, so a strict client
        // never combines them -- but nothing validates a TRIGGER before it
        // reaches the parser, and the parser is deliberately additive and
        // position-independent. Each unit it recognises has to contribute
        // rather than replace what came before it: the day branch runs after
        // the week branch, and weeks are the only thing that can precede it,
        // so this is the only shape that says so.
        yield 'weeks and days together' => ['-P1W2D', 12960];
        yield 'every unit at once' => ['-P2W3DT4H5M', 24725];
    }

    #[DataProvider('parsedDurations')]
    public function testDurationBecomesAnOffsetInMinutes(string $trigger, int $expected): void
    {
        $reminders = $this->remindersFrom(self::alarm('TRIGGER:' . $trigger));

        self::assertCount(1, $reminders);
        self::assertSame($expected, $reminders[0]->offset());
    }

    public function testALeadingMinusMeansBeforeTheReferencePoint(): void
    {
        $reminders = $this->remindersFrom(self::alarm('TRIGGER:-PT15M'));

        self::assertSame('Y', $reminders[0]->before());
        self::assertSame('S', $reminders[0]->related());
    }

    public function testAnUnsignedDurationMeansAfterTheReferencePoint(): void
    {
        // The sign is the only thing separating "15 minutes before" from
        // "15 minutes after", and the default is "before".
        $reminders = $this->remindersFrom(self::alarm('TRIGGER:PT5M'));

        self::assertSame('N', $reminders[0]->before());
        self::assertSame(5, $reminders[0]->offset());
    }

    public function testAnExplicitPlusIsStrippedBeforeParsing(): void
    {
        $reminders = $this->remindersFrom(self::alarm('TRIGGER:+PT5M'));

        self::assertSame('N', $reminders[0]->before());
        self::assertSame(5, $reminders[0]->offset());
    }

    public function testRelatedEndIsCarriedThrough(): void
    {
        $reminders = $this->remindersFrom(self::alarm('TRIGGER;RELATED=END:PT5M'));

        self::assertSame('E', $reminders[0]->related());
        self::assertSame('N', $reminders[0]->before());
    }

    public function testRelatedStartIsTheDefault(): void
    {
        $reminders = $this->remindersFrom(self::alarm('TRIGGER;RELATED=START:-PT5M'));

        self::assertSame('S', $reminders[0]->related());
    }

    public function testTheRelatedParameterIsMatchedCaseInsensitively(): void
    {
        // Sabre hands parameter values back exactly as they arrived, so a
        // client writing RELATED=end reaches this class lower-cased.
        $reminders = $this->remindersFrom(self::alarm('TRIGGER;RELATED=end:PT5M'));

        self::assertSame('E', $reminders[0]->related());
    }

    public function testAnAbsoluteTriggerFallsBackToTheStartOfTheEvent(): void
    {
        // TRIGGER can also be a DATE-TIME. There is nowhere to put an absolute
        // instant in a Reminder, so it degrades to "at the start" rather than
        // being read as an offset -- but it must not come back as "after".
        $reminders = $this->remindersFrom(
            self::alarm('TRIGGER;VALUE=DATE-TIME:20260401T100000Z'),
        );

        self::assertCount(1, $reminders);
        self::assertSame(0, $reminders[0]->offset());
        self::assertSame('Y', $reminders[0]->before());
        self::assertSame('S', $reminders[0]->related());
    }

    public function testAnAlarmWithoutATriggerDoesNotHideTheOnesAfterIt(): void
    {
        $reminders = $this->remindersFrom(
            self::alarm('X-WR-ALARMUID:no-trigger-here') . self::alarm('TRIGGER:-PT15M'),
        );

        self::assertCount(1, $reminders);
        self::assertSame(15, $reminders[0]->offset());
    }

    public function testTheStoredActionIsNormalisedToUpperCase(): void
    {
        // Enumerated iCalendar values are case-insensitive, so a lower-cased
        // ACTION is a real thing to receive rather than a hypothetical.
        $reminders = $this->remindersFrom(self::alarm('TRIGGER:-PT15M', 'audio'));

        self::assertSame('AUDIO', $reminders[0]->action());
    }

    public function testAnAlarmWithoutAnActionIsTreatedAsDisplay(): void
    {
        $reminders = $this->remindersFrom(self::alarm('TRIGGER:-PT15M', null));

        self::assertSame('DISPLAY', $reminders[0]->action());
    }

    public function testAnUnsupportedActionIsTreatedAsDisplay(): void
    {
        $reminders = $this->remindersFrom(self::alarm('TRIGGER:-PT15M', 'PROCEDURE'));

        self::assertSame('DISPLAY', $reminders[0]->action());
    }

    public function testAnAlarmWithoutATriggerIsSkipped(): void
    {
        $reminders = $this->remindersFrom(self::alarm('X-WR-ALARMUID:no-trigger-here'));

        self::assertSame([], $reminders);
    }

    public function testATriggerOutsideAValarmIsNotAReminder(): void
    {
        // Only VALARM children are considered: a component of another type that
        // happens to carry a TRIGGER must not produce a reminder.
        $ics = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:test@example.com\r\n"
            . "TRIGGER:-PT30M\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

        $vcalendar = VObject\Reader::read($ics);
        self::assertInstanceOf(VObject\Component::class, $vcalendar);

        self::assertSame([], $this->helper->extractReminders($vcalendar, 1));
    }

    public function testEveryAlarmInTheComponentIsExtracted(): void
    {
        $reminders = $this->remindersFrom(
            self::alarm('TRIGGER:-PT15M', 'DISPLAY') . self::alarm('TRIGGER:-P1D', 'EMAIL'),
        );

        self::assertCount(2, $reminders);
        self::assertSame([15, 1440], array_map(
            static fn(Reminder $r): int => $r->offset(),
            $reminders,
        ));
        self::assertSame('EMAIL', $reminders[1]->action());
    }

    // ------------------------------------------------------------- round trip

    /** @return iterable<string, array{int}> */
    public static function roundTrippableOffsets(): iterable
    {
        foreach ([0, 15, 60, 90, 118, 1440, 1560, 2878, 10080] as $offset) {
            yield $offset . ' minutes' => [$offset];
        }
    }

    #[DataProvider('roundTrippableOffsets')]
    public function testAnOffsetSurvivesWritingAndReadingBack(int $offset): void
    {
        $valarm = $this->valarmFor(new Reminder(
            eventId: 1,
            offset: $offset,
            related: 'S',
            before: 'Y',
            action: 'DISPLAY',
        ));

        $reminders = $this->remindersFrom(self::alarm('TRIGGER:' . (string) $valarm->TRIGGER));

        self::assertSame($offset, $reminders[0]->offset());
    }
}
