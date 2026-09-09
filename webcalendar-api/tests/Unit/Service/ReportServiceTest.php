<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\ReportService;
use App\Service\TenantAwarePdoProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ReportServiceTest extends TestCase
{
    /**
     * Frozen "today". The upcoming report is the only one that reads the clock,
     * and its whole behaviour is a window measured from this instant.
     */
    private const string NOW = '2026-03-14 09:30:00';

    private ReportService $service;

    #[\Override]
    protected function setUp(): void
    {
        $this->service = $this->serviceOn($this->seededPdo());
    }

    private function serviceOn(\PDO $pdo): ReportService
    {
        return new ReportService(new TenantAwarePdoProvider($pdo), new MockClock(self::NOW));
    }

    /**
     * @param bool $stringifyFetches mimic MySQL, which hands every column back
     *                               as a string; SQLite returns native types
     */
    private function seededPdo(bool $stringifyFetches = false): \PDO
    {
        $pdo = new \PDO('sqlite::memory:', null, null, [
            \PDO::ATTR_STRINGIFY_FETCHES => $stringifyFetches,
        ]);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $pdo->exec("CREATE TABLE webcal_entry (
            cal_id INTEGER PRIMARY KEY, cal_name VARCHAR(200) DEFAULT '',
            cal_description TEXT DEFAULT '', cal_date INTEGER DEFAULT 0,
            cal_time INTEGER DEFAULT 0, cal_type CHAR(1) DEFAULT 'E',
            cal_create_by VARCHAR(60) DEFAULT '', cal_duration INTEGER DEFAULT 0,
            cal_mod_date INTEGER DEFAULT 0, cal_mod_time INTEGER DEFAULT 0,
            cal_access CHAR(1) DEFAULT 'P'
        )");
        $pdo->exec('CREATE TABLE webcal_entry_categories (cal_id INTEGER, cat_id INTEGER)');
        $pdo->exec('CREATE TABLE webcal_categories (cat_id INTEGER PRIMARY KEY, cat_name VARCHAR(100), cat_owner VARCHAR(60))');

        // cal_id, name, date, time, type, owner. The dates straddle the seven
        // day window that starts at NOW: 20260321 is its last day and 20260322
        // the first day outside it.
        $rows = [
            [1, 'Morning Standup', 20260315, 90000, 'E', 'admin'],
            [2, 'Lunch Meeting', 20260315, 120000, 'E', 'admin'],
            [3, 'Review', 20260316, 140000, 'E', 'admin'],
            [4, 'Quarter Kickoff', 20260401, 100000, 'E', 'admin'],
            [5, 'Not Mine', 20260315, 90000, 'E', 'bob'],
            [6, 'Last Day In Window', 20260321, 80000, 'T', 'admin'],
            [7, 'Just Outside', 20260322, 80000, 'E', 'admin'],
            [8, 'All Day Offsite', 20260316, 0, 'E', 'admin'],
        ];
        $insert = $pdo->prepare(
            'INSERT INTO webcal_entry (cal_id, cal_name, cal_date, cal_time, cal_type, cal_create_by)
             VALUES (?, ?, ?, ?, ?, ?)',
        );
        foreach ($rows as $row) {
            $insert->execute($row);
        }

        $pdo->exec("INSERT INTO webcal_categories VALUES (1, 'Work', 'admin')");
        $pdo->exec("INSERT INTO webcal_categories VALUES (2, 'Personal', 'admin')");
        $pdo->exec('INSERT INTO webcal_entry_categories VALUES (1, 1), (2, 1), (3, 2)');

        return $pdo;
    }

    // --------------------------------------------------------------- activity

    public function testActivityReportCountsEventsPerDay(): void
    {
        $result = $this->service->activityReport('admin', '20260301', '20260331');

        self::assertSame([
            ['date' => '20260315', 'count' => 2],
            ['date' => '20260316', 'count' => 2],
            ['date' => '20260321', 'count' => 1],
            ['date' => '20260322', 'count' => 1],
        ], $result);
    }

    public function testActivityReportHonoursBothEndsOfTheRange(): void
    {
        $result = $this->service->activityReport('admin', '20260316', '20260321');

        self::assertSame(['20260316', '20260321'], array_column($result, 'date'));
    }

    // ------------------------------------------------------------- busy hours

    public function testBusyHoursReportCountsEventsPerHourOfDay(): void
    {
        $result = $this->service->busyHoursReport('admin', '20260301', '20260331');

        // 20260316 08:00 and 20260322 08:00 land in the same bucket; the
        // all-day event has cal_time 0 and is excluded by the query.
        self::assertSame([
            ['hour' => 8, 'count' => 2],
            ['hour' => 9, 'count' => 1],
            ['hour' => 12, 'count' => 1],
            ['hour' => 14, 'count' => 1],
        ], $result);
    }

    // ------------------------------------------------------------- categories

    public function testCategoriesReportCountsEventsPerCategoryMostUsedFirst(): void
    {
        $result = $this->service->categoriesReport('admin', '20260301', '20260331');

        self::assertSame([
            ['category_id' => 1, 'category_name' => 'Work', 'count' => 2],
            ['category_id' => 2, 'category_name' => 'Personal', 'count' => 1],
        ], $result);
    }

    // --------------------------------------------------------------- upcoming

    public function testUpcomingReportReturnsTheNextSevenDaysByDefault(): void
    {
        $result = $this->service->upcomingReport('admin');

        // Ordered by date then time, so the all-day event on the 16th comes
        // before the 14:00 review on the same day.
        self::assertSame([1, 2, 8, 3, 6], array_column($result, 'id'));
    }

    public function testUpcomingReportCarriesEveryStoredField(): void
    {
        $result = $this->service->upcomingReport('admin');

        self::assertSame([
            'id' => 6,
            'title' => 'Last Day In Window',
            'start_date' => '20260321',
            'type' => 'T',
        ], $result[4]);
    }

    public function testUpcomingReportWindowEndsWithTheRequestedDay(): void
    {
        // 20260321 is exactly seven days out and must be inside the window;
        // 20260322 is the first day outside it.
        self::assertContains(6, array_column($this->service->upcomingReport('admin', 7), 'id'));
        self::assertNotContains(7, array_column($this->service->upcomingReport('admin', 7), 'id'));
        self::assertContains(7, array_column($this->service->upcomingReport('admin', 8), 'id'));
    }

    public function testUpcomingReportStartsAtToday(): void
    {
        // Everything before NOW is excluded however wide the window is asked
        // to be, and a one day window still reaches tomorrow.
        self::assertSame([1, 2], array_column($this->service->upcomingReport('admin', 1), 'id'));
    }

    public function testUpcomingReportExcludesPastEvents(): void
    {
        $service = new ReportService(
            new TenantAwarePdoProvider($this->seededPdo()),
            new MockClock('2026-03-20 00:00:00'),
        );

        self::assertSame([6, 7], array_column($service->upcomingReport('admin', 7), 'id'));
    }

    // ------------------------------------------------------------------ shared

    public function testEveryReportFiltersByUser(): void
    {
        // bob owns one event inside every window used above.
        self::assertSame([], $this->service->activityReport('nobody', '20260301', '20260331'));
        self::assertSame([], $this->service->busyHoursReport('nobody', '20260301', '20260331'));
        self::assertSame([], $this->service->categoriesReport('nobody', '20260301', '20260331'));
        self::assertSame([], $this->service->upcomingReport('nobody'));

        self::assertSame(
            [['date' => '20260315', 'count' => 1]],
            $this->service->activityReport('bob', '20260301', '20260331'),
        );
    }

    public function testResultsAreTypedTheSameWhicheverWayTheDriverReturnsColumns(): void
    {
        // SQLite hands back native ints; MySQL stringifies every column by
        // default. The row mapping has to produce the same shape on both, and
        // a guard that tests the PHP type of a value rather than its content
        // silently drops the field on one of them.
        $mysqlish = $this->serviceOn($this->seededPdo(stringifyFetches: true));

        self::assertSame(
            $this->service->activityReport('admin', '20260301', '20260331'),
            $mysqlish->activityReport('admin', '20260301', '20260331'),
        );
        self::assertSame(
            $this->service->busyHoursReport('admin', '20260301', '20260331'),
            $mysqlish->busyHoursReport('admin', '20260301', '20260331'),
        );
        self::assertSame(
            $this->service->categoriesReport('admin', '20260301', '20260331'),
            $mysqlish->categoriesReport('admin', '20260301', '20260331'),
        );
        self::assertSame(
            $this->service->upcomingReport('admin'),
            $mysqlish->upcomingReport('admin'),
        );
    }
}
