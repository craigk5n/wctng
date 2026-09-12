<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Api\Event\ConflictsController;
use App\Security\WebCalendarUser;
use App\Service\ConflictDetectionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use WebCalendar\Core\Application\Service\EventService;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\Repository\UserRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;

/**
 * "Does this slot clash with anything?"
 *
 * Nothing executed a line of it. The whole point of the route is to warn
 * somebody before they double-book themselves, which makes a silent "no
 * conflicts" the one wrong answer that costs something -- and the window it
 * checks is built entirely out of query parameters nobody validated.
 */
final class ConflictsControllerTest extends TestCase
{
    /** @var list<DateRange> */
    private array $queries = [];
    /** @var list<Event> */
    private array $existing = [];

    private function controller(): ConflictsController
    {
        $events = $this->createMock(EventRepositoryInterface::class);
        $events->method('findByDateRange')->willReturnCallback(
            function (DateRange $range): array {
                $this->queries[] = $range;

                return $this->existing;
            },
        );

        return new ConflictsController(
            new EventService($events, $this->createMock(UserRepositoryInterface::class)),
            new ConflictDetectionService(),
        );
    }

    private static function user(string $login = 'alice'): WebCalendarUser
    {
        return new WebCalendarUser(new User($login, 'A', 'B', "{$login}@example.com", false, true), null);
    }

    private static function request(string $query): Request
    {
        return Request::create('/api/v2/events/conflicts?' . $query);
    }

    /** @return array<string, mixed> */
    private static function payload(JsonResponse $response): array
    {
        $body = $response->getContent();
        self::assertIsString($body);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private static function event(
        int $id,
        string $start,
        int $duration = 60,
        string $createdBy = 'alice',
        bool $allDay = false,
        string $name = 'Standup',
    ): Event {
        return new Event(
            id: new EventId($id),
            uid: "e{$id}@x",
            name: $name,
            description: '',
            location: '',
            start: new \DateTimeImmutable($start),
            duration: $duration,
            createdBy: $createdBy,
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
            allDay: $allDay,
        );
    }

    /** @return list<int> */
    private function conflictIdsFor(string $query): array
    {
        $response = ($this->controller())(self::request($query), self::user());
        self::assertSame(200, $response->getStatusCode());

        /** @var list<array{id: int}> $data */
        $data = self::payload($response)['data'];

        return array_column($data, 'id');
    }

    // ------------------------------------------------------------------- guards

    public function testItNeedsASession(): void
    {
        $response = ($this->controller())(self::request('start=20260911&end=20260911'), null);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame([], $this->queries);
    }

    #[DataProvider('rangesItCannotCheck')]
    public function testARangeItCannotCheckIsRefused(string $query, string $message): void
    {
        $response = ($this->controller())(self::request($query), self::user());

        $this->assertSame(400, $response->getStatusCode());
        // A caller who left a parameter out and one who sent a bad date need
        // different things said to them; both end in a 400, so the status
        // alone does not tell them apart.
        $this->assertSame($message, self::payload($response)['error']['message']);
        $this->assertSame([], $this->queries, 'nothing should be read for a range that was refused');
    }

    /** @return iterable<string, array{string, string}> */
    public static function rangesItCannotCheck(): iterable
    {
        $missing = 'Missing required query params: start, end (YYYYMMDD)';
        $invalid = 'Invalid date format. Expected YYYYMMDD.';

        yield 'no start' => ['end=20260911', $missing];
        yield 'no end' => ['start=20260911', $missing];
        yield 'empty start' => ['start=&end=20260911', $missing];
        yield 'words' => ['start=tomorrow&end=20260911', $invalid];
        yield 'the wrong shape' => ['start=2026-09-11&end=2026-09-12', $invalid];
        // Rolled forward rather than refused, so the check ran over days
        // nobody asked about and reported whatever it found there.
        yield 'the 31st of February' => ['start=20260231&end=20260301', $invalid];
        yield 'the 13th month' => ['start=20260101&end=20261345', $invalid];
    }

    public function testARangeThatEndsBeforeItStartsIsRefused(): void
    {
        // The window is widened a day at each end, and DateRange refuses the
        // pair that leaves -- uncaught, so this was a 500 rather than a 400.
        $response = ($this->controller())(self::request('start=20260910&end=20260901'), self::user());

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $this->queries);
    }

    #[DataProvider('durationsThatAreNotOne')]
    public function testASlotWithNoLengthIsRefused(string $query): void
    {
        // With no length, or a negative one, the slot ends before it begins
        // and overlaps nothing -- so the route answers "nothing clashes" to a
        // question it never actually asked. A warning that stays silent is
        // worse than an error.
        $this->existing = [self::event(1, '2026-09-11 10:00:00')];

        $response = ($this->controller())(self::request($query), self::user());

        $this->assertSame(400, $response->getStatusCode());
    }

    /** @return iterable<string, array{string}> */
    public static function durationsThatAreNotOne(): iterable
    {
        yield 'none at all' => ['start=20260911&end=20260911&duration=0'];
        yield 'negative' => ['start=20260911&end=20260911&duration=-60'];
    }

    public function testTheShortestSlotIsStillASlot(): void
    {
        $this->existing = [self::event(1, '2026-09-11 10:00:00')];

        $response = ($this->controller())(
            self::request('start=20260911&end=20260911&duration=1'),
            self::user(),
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    // -------------------------------------------------------------- what clashes

    public function testAnOverlappingSlotIsReported(): void
    {
        // The check event starts at midnight on the 11th and runs 60 minutes.
        $this->existing = [self::event(7, '2026-09-11 00:30:00', 60, name: 'Sprint review')];

        $response = ($this->controller())(self::request('start=20260911&end=20260911'), self::user());

        $this->assertSame([[
            'id' => 7,
            'title' => 'Sprint review',
            'start' => '2026-09-11T00:30:00',
            'end' => '2026-09-11T01:30:00',
        ]], self::payload($response)['data']);
    }

    public function testSomethingThatEndsWhenTheSlotBeginsDoesNotClash(): void
    {
        $this->existing = [self::event(1, '2026-09-10 23:00:00', 60)];

        $this->assertSame([], $this->conflictIdsFor('start=20260911&end=20260911'));
    }

    public function testSomethingThatBeginsWhenTheSlotEndsDoesNotClash(): void
    {
        $this->existing = [self::event(1, '2026-09-11 01:00:00', 60)];

        $this->assertSame([], $this->conflictIdsFor('start=20260911&end=20260911&duration=60'));
    }

    public function testALongerSlotReachesFurther(): void
    {
        $this->existing = [self::event(1, '2026-09-11 02:00:00', 30)];

        $this->assertSame([], $this->conflictIdsFor('start=20260911&end=20260911&duration=60'));
        $this->assertSame([1], $this->conflictIdsFor('start=20260911&end=20260911&duration=180'));
    }

    public function testNobodyElsesCalendarCanClashWithYours(): void
    {
        $this->existing = [
            self::event(1, '2026-09-11 00:30:00', 60, createdBy: 'bob'),
            self::event(2, '2026-09-11 00:30:00', 60, createdBy: 'alice'),
        ];

        $this->assertSame([2], $this->conflictIdsFor('start=20260911&end=20260911'));
    }

    public function testAnEventBeingEditedDoesNotClashWithItself(): void
    {
        $this->existing = [
            self::event(5, '2026-09-11 00:30:00', 60),
            self::event(6, '2026-09-11 00:30:00', 60),
        ];

        $this->assertSame([6], $this->conflictIdsFor('start=20260911&end=20260911&exclude_id=5'));
    }

    public function testExcludingNothingExcludesNothing(): void
    {
        // exclude_id defaults to 0, which is not an id -- it has to mean "no
        // exclusion" rather than matching the id every unsaved event carries.
        $this->existing = [self::event(0, '2026-09-11 00:30:00', 60)];

        $this->assertSame([0], $this->conflictIdsFor('start=20260911&end=20260911&exclude_id=0'));
    }

    #[DataProvider('allDayCombinations')]
    public function testAnAllDayEntryIsABannerRatherThanABooking(string $query, bool $existingAllDay): void
    {
        // Matching Google and Apple: an all-day entry never blocks a slot,
        // whichever side of the comparison it is on.
        $this->existing = [self::event(1, '2026-09-11 00:30:00', 60, allDay: $existingAllDay)];

        $this->assertSame([], $this->conflictIdsFor($query));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function allDayCombinations(): iterable
    {
        yield 'the slot being checked is all day' => ['start=20260911&end=20260911&all_day=1', false];
        yield 'the existing entry is all day' => ['start=20260911&end=20260911', true];
        yield 'both are' => ['start=20260911&end=20260911&all_day=1', true];
    }

    public function testAnEmptyCalendarClashesWithNothing(): void
    {
        $this->assertSame([], $this->conflictIdsFor('start=20260911&end=20260911'));
    }

    // ------------------------------------------------------------- the window

    public function testTheWindowReachesADayEitherSideOfTheRange(): void
    {
        // Something starting late on the day before can still run into the
        // range, so the read has to reach past both ends of it.
        ($this->controller())(self::request('start=20260911&end=20260913'), self::user());

        $this->assertCount(1, $this->queries);
        $this->assertSame('2026-09-10 00:00:00', $this->queries[0]->startDate()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-14 00:00:00', $this->queries[0]->endDate()->format('Y-m-d H:i:s'));
    }
}
