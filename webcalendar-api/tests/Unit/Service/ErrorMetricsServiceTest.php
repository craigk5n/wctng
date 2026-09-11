<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\ErrorMetricsService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The 5xx counter behind /health and the dashboard.
 *
 * Its only test lived in the integration suite, which the mutation run does
 * not execute, and it used the real clock -- so every error it recorded landed
 * in today's bucket and nothing about the day window could be asserted. The
 * default of one day, the length of the window, and which keys cleanup()
 * removes were all unchecked. The clock is injectable, so none of that needs a
 * real calendar.
 */
final class ErrorMetricsServiceTest extends TestCase
{
    private const string NOW = '2026-09-11T12:00:00+00:00';

    private \PDO $pdo;

    #[\Override]
    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    private function serviceAt(string $when = self::NOW): ErrorMetricsService
    {
        return new ErrorMetricsService($this->pdo, new MockClock($when));
    }

    private function storedTimestamp(): int
    {
        return (int) $this->pdo->query('SELECT updated_at FROM system_metrics')?->fetchColumn();
    }

    /** @return list<string> */
    private function storedKeys(): array
    {
        $rows = $this->pdo->query('SELECT metric_key FROM system_metrics ORDER BY metric_key')
            ?->fetchAll(\PDO::FETCH_COLUMN);

        return \is_array($rows) ? $rows : [];
    }

    // ------------------------------------------------------------ counting

    public function testNothingRecordedIsNoErrors(): void
    {
        self::assertSame(0, $this->serviceAt()->getRecentErrorCount());
    }

    public function testRepeatedErrorsOnOneDayAddUp(): void
    {
        // The upsert's whole point: the second error updates the day's row
        // rather than inserting a second one, which the primary key would
        // refuse anyway.
        $service = $this->serviceAt();
        $service->recordError();
        $service->recordError();
        $service->recordError();

        self::assertSame(3, $service->getRecentErrorCount());
        self::assertSame(['errors_2026-09-11'], $this->storedKeys());
    }

    public function testEachDayIsCountedSeparately(): void
    {
        $this->serviceAt('2026-09-10T23:00:00+00:00')->recordError();
        $this->serviceAt('2026-09-11T01:00:00+00:00')->recordError();

        self::assertSame(['errors_2026-09-10', 'errors_2026-09-11'], $this->storedKeys());
    }

    public function testTheDaysRowRecordsWhenItWasLastTouched(): void
    {
        // updated_at is written on both the insert and the update and read by
        // nothing, so a wrong value here is invisible through this class's own
        // methods -- which is exactly how it would come to be wrong.
        $morning = new \DateTimeImmutable('2026-09-11T08:00:00+00:00');
        $evening = new \DateTimeImmutable('2026-09-11T20:00:00+00:00');

        $this->serviceAt($morning->format('c'))->recordError();
        self::assertSame($morning->getTimestamp(), $this->storedTimestamp());

        // The same day again: the row is updated, and the stamp moves with it.
        $this->serviceAt($evening->format('c'))->recordError();
        self::assertSame($evening->getTimestamp(), $this->storedTimestamp());
        self::assertSame(2, $this->serviceAt()->getRecentErrorCount());
    }

    // -------------------------------------------------------- the window

    public function testTheDefaultWindowIsTodayAlone(): void
    {
        // HealthController calls this with no argument, so the default is the
        // number it reports.
        $this->serviceAt('2026-09-10T12:00:00+00:00')->recordError();
        $this->serviceAt()->recordError();

        self::assertSame(1, $this->serviceAt()->getRecentErrorCount());
    }

    /** @return iterable<string, array{int, int}> */
    public static function windowsAndWhatTheyReach(): iterable
    {
        // One error a day for the seven days ending today. A window of N days
        // covers today and the N-1 before it.
        yield 'today only' => [1, 1];
        yield 'today and yesterday' => [2, 2];
        yield 'three days' => [3, 3];
        yield 'the week the dashboard asks for' => [7, 7];
        yield 'longer than there is data for' => [10, 7];
    }

    #[DataProvider('windowsAndWhatTheyReach')]
    public function testAWindowReachesBackExactlyAsFarAsItSays(int $days, int $expected): void
    {
        for ($i = 0; $i < 7; $i++) {
            $day = (new \DateTimeImmutable(self::NOW))->modify("-{$i} days")->format('Y-m-d');
            $this->serviceAt($day . 'T12:00:00+00:00')->recordError();
        }

        self::assertSame($expected, $this->serviceAt()->getRecentErrorCount($days));
    }

    public function testTheDayBeyondTheWindowIsLeftOut(): void
    {
        // The boundary the off-by-one lives on: with a seven-day window, the
        // eighth day back must not be counted.
        $this->serviceAt('2026-09-04T12:00:00+00:00')->recordError();

        self::assertSame(0, $this->serviceAt()->getRecentErrorCount(7));
        self::assertSame(1, $this->serviceAt()->getRecentErrorCount(8));
    }

    public function testTheCountIsAnIntWhicheverWayTheDriverReturnsIt(): void
    {
        // MySQL returns SUM() as a string and SQLite as an int. Callers put
        // this straight into a JSON response, where 3 and "3" are not the same
        // document.
        $this->pdo->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, true);
        $service = $this->serviceAt();
        $service->recordError();

        self::assertSame(1, $service->getRecentErrorCount());
    }

    // --------------------------------------------------------- cleanup

    public function testCleanupDropsWhatIsOlderThanThirtyDays(): void
    {
        $this->serviceAt('2026-08-01T12:00:00+00:00')->recordError();
        $this->serviceAt()->recordError();

        $this->serviceAt()->cleanup();

        self::assertSame(['errors_2026-09-11'], $this->storedKeys());
    }

    public function testCleanupKeepsTheDayThatIsExactlyThirtyDaysOld(): void
    {
        // Thirty days back from 2026-09-11 is 2026-08-12, and the comparison
        // is strictly less than, so that day stays.
        $this->serviceAt('2026-08-12T12:00:00+00:00')->recordError();
        $this->serviceAt('2026-08-11T12:00:00+00:00')->recordError();

        $this->serviceAt()->cleanup();

        self::assertSame(['errors_2026-08-12'], $this->storedKeys());
    }

    public function testCleanupLeavesMetricsThatAreNotErrorCountsAlone(): void
    {
        // The table is general-purpose; the LIKE is what stops this deleting
        // somebody else's rows because they sort earlier.
        $this->serviceAt('2026-01-01T12:00:00+00:00')->recordError();
        $this->pdo->prepare('INSERT INTO system_metrics (metric_key, metric_value, updated_at) VALUES (:k, 1, 0)')
            ->execute(['k' => 'aaa_ancient_counter']);

        $this->serviceAt()->cleanup();

        self::assertSame(['aaa_ancient_counter'], $this->storedKeys());
    }
}
