<?php

declare(strict_types=1);

namespace App\Tests\Unit\DTO;

use App\DTO\EventRequestDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\EventType;

final class EventRequestDTOTest extends TestCase
{
    public function testCreatesEventFromValidData(): void
    {
        $data = [
            'title' => 'Test Event',
            'start_date' => '20260315',
            'start_time' => '100000',
            'duration' => 60,
        ];
        $event = EventRequestDTO::toEntity($data, 'admin');

        $this->assertInstanceOf(Event::class, $event);
        $this->assertSame('Test Event', $event->name());
        $this->assertSame('2026-03-15', $event->start()->format('Y-m-d'));
        $this->assertSame('10:00:00', $event->start()->format('H:i:s'));
        $this->assertSame(60, $event->duration());
        $this->assertFalse($event->isAllDay());
    }

    public function testCreatesEventWithAllFields(): void
    {
        $data = [
            'title' => 'Full Event',
            'description' => 'A description',
            'start_date' => '20260401',
            'start_time' => '143000',
            'duration' => 90,
            'location' => 'Room B',
            'access' => 'C',
            'type' => 'E',
            'priority' => 3,
        ];
        $event = EventRequestDTO::toEntity($data, 'testuser');

        $this->assertSame('Full Event', $event->name());
        $this->assertSame('A description', $event->description());
        $this->assertSame('Room B', $event->location());
        $this->assertSame(AccessLevel::CONFIDENTIAL, $event->access());
        $this->assertSame(EventType::EVENT, $event->type());
        $this->assertSame('testuser', $event->createdBy());
        $this->assertSame(90, $event->duration());
    }

    public function testAllDayEventSetsAllDayFlag(): void
    {
        $data = ['title' => 'All Day', 'start_date' => '20260315'];
        $event = EventRequestDTO::toEntity($data, 'admin');

        $this->assertTrue($event->isAllDay());
        $this->assertSame('2026-03-15', $event->start()->format('Y-m-d'));
    }

    public function testThrowsOnMissingTitle(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('title');
        EventRequestDTO::toEntity(['start_date' => '20260315'], 'admin');
    }

    public function testThrowsOnMissingStartDate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('start_date');
        EventRequestDTO::toEntity(['title' => 'Test'], 'admin');
    }

    public function testDefaultAccessIsPublic(): void
    {
        $data = ['title' => 'Test', 'start_date' => '20260315'];
        $event = EventRequestDTO::toEntity($data, 'admin');

        $this->assertSame(AccessLevel::PUBLIC, $event->access());
    }

    public function testDefaultTypeIsEvent(): void
    {
        $data = ['title' => 'Test', 'start_date' => '20260315'];
        $event = EventRequestDTO::toEntity($data, 'admin');

        $this->assertSame(EventType::EVENT, $event->type());
    }

    public function testDefaultDurationIsZero(): void
    {
        $data = ['title' => 'Test', 'start_date' => '20260315', 'start_time' => '100000'];
        $event = EventRequestDTO::toEntity($data, 'admin');

        $this->assertSame(0, $event->duration());
    }

    // ------------------------------------------- the date and time it parses

    public function testEveryPartOfTheStartTimeLandsInTheRightPlace(): void
    {
        // The existing cases use times whose digits repeat -- "100000" and
        // "143000" -- so an offset that slips by one still slices the same
        // characters out, and neither asserts the parsed time anyway. With
        // six distinct digits, each substr has to be reading its own pair.
        $event = EventRequestDTO::toEntity(
            ['title' => 'Precise', 'start_date' => '20261105', 'start_time' => '143052'],
            'admin',
        );

        $this->assertSame('2026-11-05 14:30:52', $event->start()->format('Y-m-d H:i:s'));
        $this->assertFalse($event->isAllDay());
    }

    public function testAnAllDayEventStartsAtMidnightOnTheRightDay(): void
    {
        // The all-day branch slices the date again, separately from the timed
        // branch above, so it needs its own case.
        $event = EventRequestDTO::toEntity(
            ['title' => 'All Day', 'start_date' => '20261105'],
            'admin',
        );

        $this->assertSame('2026-11-05 00:00:00', $event->start()->format('Y-m-d H:i:s'));
        $this->assertTrue($event->isAllDay());
    }

    /** @return iterable<string, array{string, string}> */
    public static function unparseableDates(): iterable
    {
        // Only structurally broken input is refused -- the sliced string does
        // not fit 'Y-m-d H:i:s' at all, so createFromFormat() returns false.
        yield 'too short' => ['2026', ''];
        yield 'not digits' => ['not-a-date', ''];
        yield 'empty date with a time' => ['', '143052'];
    }

    #[DataProvider('unparseableDates')]
    public function testADateThatCannotBeParsedIsRejected(string $date, string $time): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EventRequestDTO::toEntity(
            ['title' => 'Bad', 'start_date' => $date, 'start_time' => $time],
            'admin',
        );
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function outOfRangeDates(): iterable
    {
        // Recorded rather than asserted as desirable: createFromFormat() rolls
        // an out-of-range component over instead of rejecting it, so these
        // reach the database as real events on plausible-looking dates the
        // client never asked for. Validating them would mean checking
        // getLastErrors() for warnings, which changes these requests from 200
        // to 400 -- a product decision, not a test one.
        yield 'a thirteenth month' => ['20261301', '', '2027-01-01 00:00:00'];
        yield 'the thirtieth of February' => ['20260230', '', '2026-03-02 00:00:00'];
        yield 'the twenty-fifth hour' => ['20261105', '250000', '2026-11-06 01:00:00'];
        yield 'nonsense throughout' => ['20261105', '996199', '2026-11-09 04:02:39'];
    }

    #[DataProvider('outOfRangeDates')]
    public function testAnOutOfRangeDateRollsOverRatherThanBeingRefused(
        string $date,
        string $time,
        string $expected,
    ): void {
        $event = EventRequestDTO::toEntity(
            ['title' => 'Rolled over', 'start_date' => $date, 'start_time' => $time],
            'admin',
        );

        $this->assertSame($expected, $event->start()->format('Y-m-d H:i:s'));
    }

    // ------------------------------------------------------- required fields

    /** @return iterable<string, array{mixed}> */
    public static function titlesThatAreNotTitles(): iterable
    {
        // requireString() rejects on three counts joined by ors, and only the
        // absent case was covered -- so either of those operators could be
        // swapped and a request carrying a number, or an empty string, would
        // be accepted as a title.
        yield 'absent' => [null];
        yield 'empty' => [''];
        yield 'a number' => [42];
        yield 'an array' => [['nested']];
        yield 'a boolean' => [true];
    }

    #[DataProvider('titlesThatAreNotTitles')]
    public function testATitleThatIsNotANonEmptyStringIsRejected(mixed $title): void
    {
        $data = ['start_date' => '20261105'];
        if ($title !== null) {
            $data['title'] = $title;
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required field: title');
        EventRequestDTO::toEntity($data, 'admin');
    }

    // -------------------------------------------------------- optional fields

    public function testATypeThatIsRecognisedIsKeptRatherThanDefaulted(): void
    {
        // `EventType::tryFrom($typeStr) ?? EventType::EVENT`. With the operands
        // the other way round every event becomes a plain event, and the only
        // existing assertions about type are on requests that asked for one.
        $event = EventRequestDTO::toEntity(
            ['title' => 'A task', 'start_date' => '20261105', 'type' => 'T'],
            'admin',
        );

        $this->assertSame(EventType::TASK, $event->type());
    }

    public function testAnUnrecognisedTypeFallsBackToAPlainEvent(): void
    {
        $event = EventRequestDTO::toEntity(
            ['title' => 'Nonsense', 'start_date' => '20261105', 'type' => 'ZZZ'],
            'admin',
        );

        $this->assertSame(EventType::EVENT, $event->type());
    }

    public function testAnEventWithNoIdGivenIsANewOne(): void
    {
        // The id defaults to zero, which is what every repository reads as
        // "insert this" -- a default of one would edit whatever holds that id.
        $event = EventRequestDTO::toEntity(
            ['title' => 'Fresh', 'start_date' => '20261105'],
            'admin',
        );

        $this->assertSame(0, $event->id()->value());
    }

    public function testAnIdThatIsGivenIsCarriedOnToTheEntity(): void
    {
        $event = EventRequestDTO::toEntity(
            ['title' => 'Existing', 'start_date' => '20261105'],
            'admin',
            77,
        );

        $this->assertSame(77, $event->id()->value());
    }

    // ------------------------------------------------------- recurrence

    public function testAValidRuleIsKeptAndMakesTheEventRepeating(): void
    {
        // buildRecurrence() short-circuits on an empty rule string. Invert
        // that test and it short-circuits on a *present* one instead, so every
        // recurring event a client creates is stored with its rule discarded
        // -- while still being typed as repeating, which is the worst of both.
        $event = EventRequestDTO::toEntity(
            ['title' => 'Weekly', 'start_date' => '20261105', 'rrule' => 'FREQ=WEEKLY;COUNT=5'],
            'admin',
        );

        $this->assertSame(EventType::REPEATING_EVENT, $event->type());
        $this->assertSame('FREQ=WEEKLY;COUNT=5', $event->recurrence()->rule()?->toString());
    }

    public function testARuleThatWillNotParseLeavesNoRecurrenceBehind(): void
    {
        // The catch swallows a bad rule rather than rejecting the request; the
        // event is still marked repeating, because that decision is made from
        // the raw string rather than from what parsed.
        $event = EventRequestDTO::toEntity(
            ['title' => 'Broken rule', 'start_date' => '20261105', 'rrule' => 'FREQ=NONSENSE'],
            'admin',
        );

        $this->assertSame(EventType::REPEATING_EVENT, $event->type());
        $this->assertNull($event->recurrence()->rule());
    }

    public function testNoRuleMeansAPlainEventWithNoRecurrence(): void
    {
        $event = EventRequestDTO::toEntity(['title' => 'Once', 'start_date' => '20261105'], 'admin');

        $this->assertSame(EventType::EVENT, $event->type());
        $this->assertNull($event->recurrence()->rule());
    }
}
