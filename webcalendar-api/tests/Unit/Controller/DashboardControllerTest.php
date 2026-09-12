<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Api\DashboardController;
use App\Security\WebCalendarUser;
use App\Service\ErrorMetricsService;
use App\Tests\Unit\EventSubscriber\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\JsonResponse;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Repository\UserRepositoryInterface;

/**
 * The admin dashboard's figures.
 *
 * Nothing executed a line of it. Every number on the page is a COUNT with a
 * date window, and a window that is off by a boundary -- or measured against
 * the wrong column -- still returns a plausible integer, so nothing about it
 * looks wrong from the outside. Each figure is pinned against rows placed
 * either side of its edge, with the clock held still.
 */
final class DashboardControllerTest extends TestCase
{
    private const string NOW = '2026-09-11T12:00:00+00:00';

    private \PDO $pdo;

    #[\Override]
    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE webcal_entry (
                cal_id INTEGER NOT NULL PRIMARY KEY,
                cal_create_by VARCHAR(60) NOT NULL,
                cal_date INT NOT NULL,
                cal_mod_date INT,
                cal_duration INT NOT NULL DEFAULT 0,
                cal_name VARCHAR(80) NOT NULL DEFAULT \'\'
            )',
        );
        $this->pdo->exec(
            'CREATE TABLE reminder_sent (
                event_id INTEGER NOT NULL,
                user_login VARCHAR(60) NOT NULL,
                sent_at INTEGER NOT NULL,
                PRIMARY KEY (event_id, user_login)
            )',
        );
        $this->pdo->exec(
            'CREATE TABLE daily_agenda_sent (
                user_login VARCHAR(60) NOT NULL,
                agenda_date VARCHAR(10) NOT NULL,
                sent_at INTEGER NOT NULL,
                PRIMARY KEY (user_login, agenda_date)
            )',
        );
    }

    // ------------------------------------------------------------- fixtures

    private function entry(string $createdBy, int $calDate, ?int $modDate): void
    {
        static $id = 0;
        ++$id;

        $this->pdo->prepare(
            'INSERT INTO webcal_entry (cal_id, cal_create_by, cal_date, cal_mod_date) VALUES (:id, :by, :date, :mod)',
        )->execute(['id' => $id, 'by' => $createdBy, 'date' => $calDate, 'mod' => $modDate]);
    }

    private function reminderSentAt(int $eventId, string $login, int $sentAt): void
    {
        $this->pdo->prepare('INSERT INTO reminder_sent (event_id, user_login, sent_at) VALUES (:e, :l, :s)')
            ->execute(['e' => $eventId, 'l' => $login, 's' => $sentAt]);
    }

    private function agendaSentAt(string $login, string $date, int $sentAt): void
    {
        $this->pdo->prepare('INSERT INTO daily_agenda_sent (user_login, agenda_date, sent_at) VALUES (:l, :d, :s)')
            ->execute(['l' => $login, 'd' => $date, 's' => $sentAt]);
    }

    /** @param list<string> $logins */
    private function userRepository(array $logins = [], bool $throws = false): UserRepositoryInterface
    {
        $repo = $this->createMock(UserRepositoryInterface::class);

        if ($throws) {
            $repo->method('findAll')->willThrowException(new \RuntimeException('no database'));

            return $repo;
        }

        $repo->method('findAll')->willReturn(array_map(
            static fn(string $login): User => new User($login, 'F', 'L', "{$login}@example.com", false, true),
            $logins,
        ));

        return $repo;
    }

    private function controller(
        ?UserRepositoryInterface $users = null,
        ?ErrorMetricsService $metrics = null,
        string $now = self::NOW,
        ?RecordingLogger $logger = null,
    ): DashboardController {
        return new DashboardController(
            $users ?? $this->userRepository(),
            $this->pdo,
            $metrics,
            new MockClock($now),
            $logger ?? new RecordingLogger(),
        );
    }

    private static function admin(): WebCalendarUser
    {
        return new WebCalendarUser(new User('admin', 'Ad', 'Min', 'admin@example.com', true, true), null);
    }

    private static function ordinaryUser(): WebCalendarUser
    {
        return new WebCalendarUser(new User('bob', 'Bob', 'Jones', 'bob@example.com', false, true), null);
    }

    /** @return array<string, mixed> */
    private static function payload(JsonResponse $response): array
    {
        $body = $response->getContent();
        self::assertIsString($body);
        /** @var array{data: array<string, mixed>} $decoded */
        $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('data', $decoded);

        return $decoded['data'];
    }

    /** @return array<string, mixed> */
    private function stats(
        ?UserRepositoryInterface $users = null,
        ?ErrorMetricsService $metrics = null,
        string $now = self::NOW,
    ): array {
        return self::payload(($this->controller($users, $metrics, $now))(self::admin()));
    }

    // ------------------------------------------------------------------ auth

    #[DataProvider('callersWithoutAccess')]
    public function testOnlyAnAdministratorSeesTheDashboard(?WebCalendarUser $caller): void
    {
        $response = ($this->controller())($caller);

        $this->assertSame(403, $response->getStatusCode());
    }

    /** @return iterable<string, array{WebCalendarUser|null}> */
    public static function callersWithoutAccess(): iterable
    {
        yield 'anonymous' => [null];
        yield 'signed in, not an admin' => [self::ordinaryUser()];
    }

    public function testAnAdministratorGetsEverySection(): void
    {
        $response = ($this->controller())(self::admin());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['users', 'events', 'system', 'email'], array_keys(self::payload($response)));
    }

    // ----------------------------------------------------------------- users

    public function testTheUserTotalIsHowManyAccountsExist(): void
    {
        $stats = $this->stats($this->userRepository(['alice', 'bob', 'carol']));

        $this->assertSame(3, $stats['users']['total']);
    }

    public function testAFailingUserStoreLeavesTheRestOfThePageStanding(): void
    {
        // The dashboard is the page an administrator opens when something is
        // already wrong; one unavailable source must not take the other three
        // sections down with it.
        $this->entry('alice', 20260912, 20260910);

        $stats = $this->stats($this->userRepository(throws: true));

        $this->assertSame(0, $stats['users']['total']);
        $this->assertSame(1, $stats['events']['total']);
        $this->assertSame('sqlite', $stats['system']['db_driver']);
    }

    public function testActiveUsersCountsEachPersonOnce(): void
    {
        $this->entry('alice', 20260905, 20260908);
        $this->entry('alice', 20260906, 20260909);
        $this->entry('bob', 20260907, 20260910);

        $this->assertSame(2, $this->stats()['users']['active_7d']);
    }

    /**
     * cal_date is when the event happens; cal_mod_date is when someone last
     * wrote the row. Measured on the former, and with no upper bound, one
     * booking for next year makes its creator "active this week" every week
     * from now until the event passes -- and someone who spent yesterday
     * tidying last month's calendar counts for nothing.
     */
    public function testBookingSomethingForNextYearIsNotActivityThisWeek(): void
    {
        $this->entry('alice', 20271231, 20240101);

        $this->assertSame(0, $this->stats()['users']['active_7d']);
    }

    public function testEditingAnOldEventThisWeekIsActivity(): void
    {
        $this->entry('alice', 20200101, 20260910);

        $this->assertSame(1, $this->stats()['users']['active_7d']);
    }

    // ---------------------------------------------------------------- events

    public function testTheEventTotalCountsEveryRow(): void
    {
        $this->entry('alice', 20200101, 20200101);
        $this->entry('bob', 20300101, 20300101);

        $this->assertSame(2, $this->stats()['events']['total']);
    }

    #[DataProvider('upcomingWindow')]
    public function testUpcomingCountsTheNextSevenDaysInclusive(int $calDate, int $expected): void
    {
        // Today is 2026-09-11, so the window runs 20260911..20260918.
        $this->entry('alice', $calDate, 20260911);

        $this->assertSame($expected, $this->stats()['events']['upcoming_7d']);
    }

    /** @return iterable<string, array{int, int}> */
    public static function upcomingWindow(): iterable
    {
        yield 'yesterday' => [20260910, 0];
        yield 'today' => [20260911, 1];
        yield 'mid week' => [20260914, 1];
        yield 'the seventh day' => [20260918, 1];
        yield 'the day after' => [20260919, 0];
    }

    #[DataProvider('recentlyTouchedWindow')]
    public function testEventsCreatedCountsTheLastSevenDaysOfChanges(?int $modDate, int $expected): void
    {
        // The cut-off is 2026-09-04.
        $this->entry('alice', 20260911, $modDate);

        $this->assertSame($expected, $this->stats()['events']['created_7d']);
    }

    /** @return iterable<string, array{int|null, int}> */
    public static function recentlyTouchedWindow(): iterable
    {
        yield 'the day before the cut-off' => [20260903, 0];
        yield 'the cut-off itself' => [20260904, 1];
        yield 'today' => [20260911, 1];
        yield 'never stamped' => [null, 0];
        yield 'a legacy zero stamp' => [0, 0];
    }

    // ---------------------------------------------------------------- system

    public function testTheSystemSectionReportsTheRuntimeItIsOn(): void
    {
        $system = $this->stats()['system'];

        $this->assertSame(\PHP_VERSION, $system['php_version']);
        $this->assertSame('sqlite', $system['db_driver']);
    }

    public function testTheDatabaseSizeIsSqlitesPageCountInMegabytes(): void
    {
        // An empty in-memory database has no pages at all, so neither the
        // arithmetic nor the rounding shows up until there is something in it.
        for ($i = 0; $i < 2000; $i++) {
            $this->entry('alice', 20260911, 20260911);
        }

        $pages = (int) $this->pdo->query('PRAGMA page_count')?->fetchColumn();
        $pageSize = (int) $this->pdo->query('PRAGMA page_size')?->fetchColumn();
        $expected = round($pages * $pageSize / 1024 / 1024, 1);
        $this->assertGreaterThan(0, $expected);

        $this->assertSame($expected, (float) $this->stats()['system']['db_size_mb']);
    }

    public function testRecentErrorsComesFromTheErrorCounter(): void
    {
        $metrics = new ErrorMetricsService($this->pdo, new MockClock(self::NOW));
        $metrics->recordError();
        $metrics->recordError();

        $this->assertSame(2, $this->stats(metrics: $metrics)['system']['recent_errors']);
    }

    public function testRecentErrorsIsZeroWhenNothingCountsThem(): void
    {
        // The service is optional in the constructor, and a null there must
        // read as "no errors", not take the page down -- and not by way of
        // the catch, which would put a failure in the log on every load of a
        // deployment that simply does not count errors.
        $logger = new RecordingLogger();

        $response = ($this->controller(null, null, self::NOW, $logger))(self::admin());

        $this->assertSame(0, self::payload($response)['system']['recent_errors']);
        $this->assertSame([], $logger->records);
    }

    public function testRecentErrorsCoversSevenDaysAndNotAnEighth(): void
    {
        // The card says "recent errors"; the window it means is the seven days
        // the rest of the page uses, and a day either side of that is a
        // different number with the same label.
        foreach (['-8 days', '-7 days', '-6 days', '-1 day', ''] as $when) {
            $at = $when === ''
                ? new \DateTimeImmutable(self::NOW)
                : (new \DateTimeImmutable(self::NOW))->modify($when);
            (new ErrorMetricsService($this->pdo, new MockClock($at)))->recordError();
        }

        $metrics = new ErrorMetricsService($this->pdo, new MockClock(self::NOW));

        // Today plus the six days behind it: -6, -1 and today, but not -7.
        $this->assertSame(3, $this->stats(metrics: $metrics)['system']['recent_errors']);
    }

    // ----------------------------------------------------------------- email

    #[DataProvider('sentWindow')]
    public function testRemindersSentCountsTheLastSevenDays(int $offsetSeconds, int $expected): void
    {
        $now = (new \DateTimeImmutable(self::NOW))->getTimestamp();
        $this->reminderSentAt(1, 'alice', $now + $offsetSeconds);

        $this->assertSame($expected, $this->stats()['email']['reminders_sent_7d']);
    }

    #[DataProvider('sentWindow')]
    public function testAgendasSentCountsTheLastSevenDays(int $offsetSeconds, int $expected): void
    {
        $now = (new \DateTimeImmutable(self::NOW))->getTimestamp();
        $this->agendaSentAt('alice', '2026-09-11', $now + $offsetSeconds);

        $this->assertSame($expected, $this->stats()['email']['agenda_sent_7d']);
    }

    /** @return iterable<string, array{int, int}> */
    public static function sentWindow(): iterable
    {
        yield 'a second before the window opens' => [-7 * 86400 - 1, 0];
        yield 'exactly seven days ago' => [-7 * 86400, 1];
        yield 'an hour ago' => [-3600, 1];
    }

    public function testTheTwoMailCountersAreReadFromTheirOwnTables(): void
    {
        // They were written from one copied block; crossed over, the page
        // reports the same figure twice and neither is what it says.
        $now = (new \DateTimeImmutable(self::NOW))->getTimestamp();
        $this->reminderSentAt(1, 'alice', $now - 3600);
        $this->reminderSentAt(2, 'alice', $now - 3600);
        $this->agendaSentAt('alice', '2026-09-11', $now - 3600);

        $email = $this->stats()['email'];

        $this->assertSame(2, $email['reminders_sent_7d']);
        $this->assertSame(1, $email['agenda_sent_7d']);
    }

    public function testTheMailCountersReadZeroBeforeAnythingHasBeenSent(): void
    {
        // Both tables are created lazily by the services that write them, so
        // on a fresh install neither exists yet and the page still has to load.
        $this->pdo->exec('DROP TABLE reminder_sent');
        $this->pdo->exec('DROP TABLE daily_agenda_sent');

        $email = $this->stats()['email'];

        $this->assertSame(0, $email['reminders_sent_7d']);
        $this->assertSame(0, $email['agenda_sent_7d']);
    }

    // ------------------------------------------------- the copied event figure

    /**
     * users.created_7d does not count users. It calls the same helper the
     * events section does, so an administrator is shown the number of events
     * touched this week under a "users" key.
     *
     * Pinned rather than corrected: webcal_user carries no creation stamp --
     * only cal_login, cal_last_login and the profile columns -- so there is no
     * source for the figure this key claims to hold. Nothing renders it today.
     */
    public function testTheUsersCreatedFigureIsActuallyTheEventFigure(): void
    {
        $this->entry('alice', 20260911, 20260910);
        $this->entry('alice', 20260911, 20260910);
        $this->entry('bob', 20260911, 20260910);

        $stats = $this->stats($this->userRepository(['alice', 'bob']));

        $this->assertSame(3, $stats['users']['created_7d']);
        $this->assertSame($stats['events']['created_7d'], $stats['users']['created_7d']);
    }

    // ------------------------------------------------ a driver that stringifies

    /**
     * MySQL's PDO hands every column back as a string, and MySQL is what
     * deployments run; SQLite does not, which is why the casts look like
     * no-ops here. Without them the dashboard answers `"1234"` where its own
     * contract -- and the client that renders it -- says 1234.
     */
    public function testEveryFigureIsANumberOnADriverThatReturnsStrings(): void
    {
        $this->pdo->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, true);
        $this->entry('alice', 20260911, 20260910);
        $now = (new \DateTimeImmutable(self::NOW))->getTimestamp();
        $this->reminderSentAt(1, 'alice', $now - 3600);
        $this->agendaSentAt('alice', '2026-09-11', $now - 3600);
        $metrics = new ErrorMetricsService($this->pdo, new MockClock(self::NOW));
        $metrics->recordError();

        $stats = $this->stats($this->userRepository(['alice']), $metrics);

        // The values as well as the types: getEventsCreated7d() declares an
        // int return inside its own try, so a string reaching that return
        // raises a TypeError the catch turns into 0 -- the figure disappears
        // rather than changing shape.
        $this->assertSame(['total' => 1, 'active_7d' => 1, 'created_7d' => 1], $stats['users']);
        $this->assertSame(['total' => 1, 'created_7d' => 1, 'upcoming_7d' => 1], $stats['events']);
        $this->assertSame(1, $stats['system']['recent_errors']);
        $this->assertSame(1, $stats['email']['reminders_sent_7d']);
        $this->assertSame(1, $stats['email']['agenda_sent_7d']);
        $this->assertIsNumeric($stats['system']['db_size_mb']);
    }

    // ------------------------------------------------------- when a source dies

    /**
     * Every figure sits behind its own catch, and the page has to load with
     * the ones that still work. Without the initialisers the surviving
     * sections would carry whatever the failed branch left behind.
     */
    public function testAMissingEventTableLeavesEveryEventFigureAtZero(): void
    {
        $this->pdo->exec('DROP TABLE webcal_entry');

        $stats = $this->stats($this->userRepository(['alice', 'bob']));

        $this->assertSame(0, $stats['users']['active_7d']);
        $this->assertSame(0, $stats['users']['created_7d']);
        $this->assertSame(0, $stats['events']['total']);
        $this->assertSame(0, $stats['events']['created_7d']);
        $this->assertSame(0, $stats['events']['upcoming_7d']);
        $this->assertSame(2, $stats['users']['total'], 'the section that still works must survive');
    }

    public function testEachFailedFigureSaysWhichOneItWas(): void
    {
        // Four of these are swallowed to keep the page up, so the log line is
        // the only record that the number shown is a fallback rather than a
        // measurement.
        $this->pdo->exec('DROP TABLE webcal_entry');
        $this->pdo->exec('DROP TABLE reminder_sent');
        $this->pdo->exec('DROP TABLE daily_agenda_sent');
        $logger = new RecordingLogger();

        ($this->controller($this->userRepository(throws: true), null, self::NOW, $logger))(self::admin());

        $messages = array_column($logger->records, 'message');
        $this->assertContains('userRepository->findAll() failed', $messages);
        $this->assertContains('active user count failed', $messages);
        $this->assertContains('pdo->query() failed', $messages);
        $this->assertContains('upcoming event count failed', $messages);
        $this->assertContains('pdo->prepare() failed', $messages);
        // Both mail counters log the same words, so one line proves nothing
        // about the other.
        $this->assertCount(2, array_keys($messages, 'pdo->prepare() failed', true));

        foreach ($logger->records as $record) {
            $this->assertArrayHasKey('exception', $record['context'], $record['message']);
            $this->assertNotSame('', $record['context']['exception'], $record['message']);
        }
    }

    public function testAFailingErrorCounterIsLoggedRatherThanFatal(): void
    {
        $metrics = new ErrorMetricsService($this->pdo, new MockClock(self::NOW));
        $this->pdo->exec('DROP TABLE system_metrics');
        $logger = new RecordingLogger();

        $response = ($this->controller(null, $metrics, self::NOW, $logger))(self::admin());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, self::payload($response)['system']['recent_errors']);
        $this->assertSame(
            [['level' => 'debug', 'message' => 'errorMetrics->getRecentErrorCount() failed', 'context' => ['exception' => $logger->records[0]['context']['exception']]]],
            $logger->records,
        );
        $this->assertNotSame('', $logger->records[0]['context']['exception']);
    }

    public function testAnUnreadableDatabaseSizeIsReportedAsUnknown(): void
    {
        // The figure is optional in the contract; the page still has to load
        // when the driver cannot answer, and say nothing rather than zero.
        $logger = new RecordingLogger();
        $broken = new class ('sqlite::memory:') extends \PDO {
            #[\Override]
            public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
            {
                if (str_starts_with($query, 'PRAGMA')) {
                    throw new \PDOException('pragma unavailable');
                }

                return parent::query($query);
            }
        };
        $broken->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $broken->exec('CREATE TABLE webcal_entry (cal_id INTEGER PRIMARY KEY, cal_create_by VARCHAR(60), cal_date INT, cal_mod_date INT)');

        $controller = new DashboardController(
            $this->userRepository(),
            $broken,
            null,
            new MockClock(self::NOW),
            $logger,
        );

        $system = self::payload($controller(self::admin()))['system'];

        $this->assertNull($system['db_size_mb']);
        $this->assertSame('sqlite', $system['db_driver']);

        $sizeFailures = array_values(array_filter(
            $logger->records,
            static fn(array $r): bool => $r['message'] === 'pdo->query() failed',
        ));
        $this->assertCount(1, $sizeFailures);
        $this->assertSame(['exception' => 'pragma unavailable'], $sizeFailures[0]['context']);
    }
}
