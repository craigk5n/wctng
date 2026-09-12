<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Api\ActivityLogController;
use App\Security\WebCalendarUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use WebCalendar\Core\Application\Service\ActivityLogService;
use WebCalendar\Core\Domain\Entity\ActivityLogEntry;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Repository\ActivityLogRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\ActivityLogType;
use WebCalendar\Core\Domain\ValueObject\DateRange;

/**
 * The administrator's audit trail.
 *
 * Nothing executed a line of it. Every answer it gives is "what happened,
 * to whom, between these two dates", and each of those three is taken from the
 * query string: a date that is not one, a range nobody checks, and a login
 * filter that silently turns itself off. An audit log that quietly answers for
 * a different day, or for everybody, is worse than one that refuses.
 */
final class ActivityLogControllerTest extends TestCase
{
    private const NOW = '2026-09-11T12:00:00+00:00';

    /** @var list<array{range: DateRange, login: string|null}> */
    private array $queries = [];
    /** @var list<ActivityLogEntry> */
    private array $entries = [];

    private function controller(): ActivityLogController
    {
        $repo = $this->createMock(ActivityLogRepositoryInterface::class);
        $repo->method('findByDateRange')->willReturnCallback(
            function (DateRange $range, ?string $login = null): array {
                $this->queries[] = ['range' => $range, 'login' => $login];

                return $this->entries;
            },
        );

        return new ActivityLogController(new ActivityLogService($repo), new MockClock(self::NOW));
    }

    private static function admin(): WebCalendarUser
    {
        return new WebCalendarUser(new User('admin', 'Ad', 'Min', 'admin@example.com', true, true), null);
    }

    private static function ordinaryUser(): WebCalendarUser
    {
        return new WebCalendarUser(new User('bob', 'Bob', 'Jones', 'bob@example.com', false, true), null);
    }

    private static function request(string $query = ''): Request
    {
        return Request::create('/api/v2/admin/activity-log?' . $query);
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

    private static function entry(
        int $id,
        string $login = 'alice',
        ActivityLogType $type = ActivityLogType::CREATE,
        string $text = 'Something happened',
    ): ActivityLogEntry {
        return new ActivityLogEntry(
            id: $id,
            entryId: 100 + $id,
            login: $login,
            userCal: 'bob',
            type: $type,
            date: new \DateTimeImmutable('2026-09-05 14:30:00'),
            text: $text,
        );
    }

    /** @return array{range: DateRange, login: string|null} */
    private function onlyQuery(): array
    {
        $this->assertCount(1, $this->queries);

        return $this->queries[0];
    }

    // ------------------------------------------------------------ who may ask

    #[DataProvider('callersWithoutAccess')]
    public function testOnlyAnAdministratorMayReadTheLog(?WebCalendarUser $caller): void
    {
        $response = $this->controller()->list(self::request(), $caller);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame([], $this->queries, 'nothing may be read for a caller who was refused');
    }

    /** @return iterable<string, array{WebCalendarUser|null}> */
    public static function callersWithoutAccess(): iterable
    {
        yield 'anonymous' => [null];
        yield 'signed in, not an admin' => [self::ordinaryUser()];
    }

    // --------------------------------------------------------------- the range

    public function testWithNoRangeItAsksAboutTheLastThirtyDays(): void
    {
        $this->controller()->list(self::request(), self::admin());

        $range = $this->onlyQuery()['range'];
        $this->assertSame('2026-08-12 12:00:00', $range->startDate()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-11 12:00:00', $range->endDate()->format('Y-m-d H:i:s'));
    }

    public function testAHalfGivenRangeFallsBackToTheDefault(): void
    {
        // Deliberate: both ends or neither. One alone would otherwise pair a
        // named date with whatever today happens to be.
        $this->controller()->list(self::request('start=20260901'), self::admin());

        $range = $this->onlyQuery()['range'];
        $this->assertSame('2026-08-12 12:00:00', $range->startDate()->format('Y-m-d H:i:s'));
    }

    public function testAGivenRangeCoversBothDaysWhole(): void
    {
        $this->controller()->list(self::request('start=20260901&end=20260910'), self::admin());

        $range = $this->onlyQuery()['range'];
        $this->assertSame('2026-09-01 00:00:00', $range->startDate()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-10 23:59:59', $range->endDate()->format('Y-m-d H:i:s'));
    }

    #[DataProvider('unusableRanges')]
    public function testADateThatIsNotOneIsRefused(string $query): void
    {
        $response = $this->controller()->list(self::request($query), self::admin());

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $this->queries, 'nothing should be read for a range that was refused');
    }

    /** @return iterable<string, array{string}> */
    public static function unusableRanges(): iterable
    {
        yield 'words for a start' => ['start=yesterday&end=20260910'];
        yield 'words for an end' => ['start=20260901&end=today'];
        yield 'the wrong shape' => ['start=2026-09-01&end=2026-09-10'];
        // createFromFormat rolls these forward rather than refusing them, so
        // the log answered for a day nobody asked about and said nothing.
        yield 'the 31st of February' => ['start=20260231&end=20260301'];
        yield 'the 13th month' => ['start=20260101&end=20261345'];
        yield 'the 32nd' => ['start=20260132&end=20260201'];
    }

    public function testARangeThatEndsBeforeItStartsIsRefused(): void
    {
        // It reads as an empty log rather than as a mistake, which is the one
        // answer an audit trail must never give by accident.
        $response = $this->controller()->list(self::request('start=20260910&end=20260901'), self::admin());

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $this->queries);
    }

    public function testARangeOfASingleDayIsFine(): void
    {
        $response = $this->controller()->list(self::request('start=20260901&end=20260901'), self::admin());

        $this->assertSame(200, $response->getStatusCode());
    }

    // --------------------------------------------------------- the user filter

    public function testTheLogCanBeNarrowedToOnePerson(): void
    {
        $this->controller()->list(self::request('user=alice'), self::admin());

        $this->assertSame('alice', $this->onlyQuery()['login']);
    }

    public function testWithNobodyNamedItAsksAboutEveryone(): void
    {
        $this->controller()->list(self::request(), self::admin());

        $this->assertNull($this->onlyQuery()['login']);
    }

    public function testALoginOfZeroStillNarrowsTheLog(): void
    {
        // `?: null` reads "0" as nothing given, so asking about the account
        // named 0 quietly returned everybody's activity instead -- the widest
        // possible answer to the narrowest possible question.
        $this->controller()->list(self::request('user=0'), self::admin());

        $this->assertSame('0', $this->onlyQuery()['login']);
    }

    // ---------------------------------------------------------------- the page

    public function testTheWholeLogIsCountedEvenWhenOnePageIsShown(): void
    {
        $this->entries = array_map(static fn(int $i): ActivityLogEntry => self::entry($i), range(1, 120));

        $payload = self::payload($this->controller()->list(self::request('limit=10&page=2'), self::admin()));

        $this->assertCount(10, $payload['data']);
        $this->assertSame(120, $payload['meta']['total']);
        $this->assertSame(2, $payload['meta']['page']);
        $this->assertSame(10, $payload['meta']['limit']);
        $this->assertSame(11, $payload['data'][0]['id'], 'the second page starts after the first');
    }

    #[DataProvider('pageBounds')]
    public function testThePageAndItsSizeAreHeldInBounds(string $query, int $expectedPage, int $expectedLimit): void
    {
        $this->entries = array_map(static fn(int $i): ActivityLogEntry => self::entry($i), range(1, 200));

        $payload = self::payload($this->controller()->list(self::request($query), self::admin()));

        $this->assertSame($expectedPage, $payload['meta']['page']);
        $this->assertSame($expectedLimit, $payload['meta']['limit']);
        $this->assertCount($expectedLimit, $payload['data']);
    }

    /** @return iterable<string, array{string, int, int}> */
    public static function pageBounds(): iterable
    {
        yield 'nothing asked for' => ['', 1, 50];
        yield 'page zero' => ['page=0', 1, 50];
        yield 'a negative page' => ['page=-3', 1, 50];
        yield 'more than the cap' => ['limit=1000', 1, 100];
        yield 'at the cap' => ['limit=100', 1, 100];
        yield 'none at all' => ['limit=0', 1, 1];
        yield 'a negative size' => ['limit=-5', 1, 1];
    }

    public function testAPageBeyondTheEndIsEmptyRatherThanAnError(): void
    {
        $this->entries = [self::entry(1)];

        $payload = self::payload($this->controller()->list(self::request('page=9'), self::admin()));

        $this->assertSame([], $payload['data']);
        $this->assertSame(1, $payload['meta']['total']);
    }

    // ------------------------------------------------------------- each entry

    public function testAnEntryCarriesEveryFieldItPromises(): void
    {
        $this->entries = [self::entry(7, 'alice', ActivityLogType::APPROVE, 'Approved the offsite')];

        $payload = self::payload($this->controller()->list(self::request(), self::admin()));

        $this->assertSame([
            'id' => 7,
            'entry_id' => 107,
            'user' => 'alice',
            'user_cal' => 'bob',
            'action' => 'approve',
            'action_code' => 'A',
            'timestamp' => '2026-09-05T14:30:00',
            'text' => 'Approved the offsite',
        ], $payload['data'][0]);
    }

    #[DataProvider('everyKindOfEntry')]
    public function testEveryKindOfEntryHasAWordForIt(ActivityLogType $type, string $label): void
    {
        // The code is what the column holds; the word is the only part an
        // administrator reads.
        $this->entries = [self::entry(1, 'alice', $type)];

        $payload = self::payload($this->controller()->list(self::request(), self::admin()));

        $this->assertSame($label, $payload['data'][0]['action']);
        $this->assertSame($type->value, $payload['data'][0]['action_code']);
    }

    /** @return iterable<string, array{ActivityLogType, string}> */
    public static function everyKindOfEntry(): iterable
    {
        yield 'create' => [ActivityLogType::CREATE, 'create'];
        yield 'approve' => [ActivityLogType::APPROVE, 'approve'];
        yield 'reject' => [ActivityLogType::REJECT, 'reject'];
        yield 'update' => [ActivityLogType::UPDATE, 'update'];
        yield 'notification' => [ActivityLogType::NOTIFICATION, 'notification'];
        yield 'reminder' => [ActivityLogType::REMINDER, 'reminder'];
        yield 'extra' => [ActivityLogType::EXTRA, 'extra'];
    }

    public function testAnEmptyLogIsAnEmptyPage(): void
    {
        $payload = self::payload($this->controller()->list(self::request(), self::admin()));

        $this->assertSame([], $payload['data']);
        $this->assertSame(0, $payload['meta']['total']);
    }
}
