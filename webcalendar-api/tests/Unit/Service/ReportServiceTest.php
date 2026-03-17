<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\CoreServiceFactory;
use App\Service\ReportService;
use PHPUnit\Framework\TestCase;

final class ReportServiceTest extends TestCase
{
    private ReportService $service;

    #[\Override]
    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $pdo->exec("CREATE TABLE webcal_entry (
            cal_id INTEGER PRIMARY KEY, cal_name VARCHAR(200) DEFAULT '',
            cal_description TEXT DEFAULT '', cal_date INTEGER DEFAULT 0,
            cal_time INTEGER DEFAULT 0, cal_type CHAR(1) DEFAULT 'E',
            cal_create_by VARCHAR(60) DEFAULT '', cal_duration INTEGER DEFAULT 0,
            cal_mod_date INTEGER DEFAULT 0, cal_mod_time INTEGER DEFAULT 0,
            cal_access CHAR(1) DEFAULT 'P'
        )");
        $pdo->exec("CREATE TABLE webcal_entry_categories (cal_id INTEGER, cat_id INTEGER)");
        $pdo->exec("CREATE TABLE webcal_categories (cat_id INTEGER PRIMARY KEY, cat_name VARCHAR(100), cat_owner VARCHAR(60))");

        // Seed data
        $pdo->exec("INSERT INTO webcal_entry VALUES (1, 'Morning Standup', '', 20260315, 90000, 'E', 'admin', 15, 0, 0, 'P')");
        $pdo->exec("INSERT INTO webcal_entry VALUES (2, 'Lunch Meeting', '', 20260315, 120000, 'E', 'admin', 60, 0, 0, 'P')");
        $pdo->exec("INSERT INTO webcal_entry VALUES (3, 'Review', '', 20260316, 140000, 'E', 'admin', 30, 0, 0, 'P')");
        $pdo->exec("INSERT INTO webcal_entry VALUES (4, 'Future Event', '', 20260401, 100000, 'E', 'admin', 60, 0, 0, 'P')");
        $pdo->exec("INSERT INTO webcal_categories VALUES (1, 'Work', 'admin')");
        $pdo->exec("INSERT INTO webcal_entry_categories VALUES (1, 1)");
        $pdo->exec("INSERT INTO webcal_entry_categories VALUES (2, 1)");

        $factory = new CoreServiceFactory($pdo, 'test');
        $this->service = new ReportService($factory);
    }

    public function testActivityReport(): void
    {
        $result = $this->service->activityReport('admin', '20260301', '20260331');
        $this->assertNotEmpty($result);

        // Should have entries for March 15 and 16
        $dates = array_map(static fn ($r) => (string) $r['date'], $result);
        $this->assertContains('20260315', $dates);
        $this->assertContains('20260316', $dates);

        // March 15 has 2 events
        foreach ($result as $r) {
            if ((string) $r['date'] === '20260315') {
                $this->assertSame(2, $r['count']);
            }
        }
    }

    public function testBusyHoursReport(): void
    {
        $result = $this->service->busyHoursReport('admin', '20260301', '20260331');
        $this->assertNotEmpty($result);

        $hours = array_column($result, 'hour');
        $this->assertContains(9, $hours);  // 09:00
        $this->assertContains(12, $hours); // 12:00
        $this->assertContains(14, $hours); // 14:00
    }

    public function testCategoriesReport(): void
    {
        $result = $this->service->categoriesReport('admin', '20260301', '20260331');
        $this->assertNotEmpty($result);
        $this->assertSame('Work', $result[0]['category_name']);
        $this->assertSame(2, $result[0]['count']);
    }

    public function testUpcomingReport(): void
    {
        // Upcoming depends on current date — just verify structure
        $result = $this->service->upcomingReport('admin', 365);
        $this->assertIsArray($result);

        if (\count($result) > 0) {
            $this->assertArrayHasKey('id', $result[0]);
            $this->assertArrayHasKey('title', $result[0]);
            $this->assertArrayHasKey('start_date', $result[0]);
        }
    }

    public function testReportsFilterByUser(): void
    {
        $result = $this->service->activityReport('nonexistent', '20260301', '20260331');
        $this->assertEmpty($result);
    }
}
