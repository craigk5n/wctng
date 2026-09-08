<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\CoreServiceFactory;
use App\Service\SearchIndexService;
use App\Service\TenantAwarePdoProvider;
use PHPUnit\Framework\TestCase;

final class SearchSuggestTest extends TestCase
{
    private SearchIndexService $service;

    #[\Override]
    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $pdo->exec("CREATE TABLE webcal_entry (
            cal_id INTEGER PRIMARY KEY,
            cal_name VARCHAR(200) DEFAULT '',
            cal_description TEXT DEFAULT '',
            cal_date INTEGER DEFAULT 0,
            cal_time INTEGER DEFAULT 0,
            cal_type CHAR(1) DEFAULT 'E',
            cal_create_by VARCHAR(60) DEFAULT '',
            cal_duration INTEGER DEFAULT 0,
            cal_mod_date INTEGER DEFAULT 0,
            cal_mod_time INTEGER DEFAULT 0,
            cal_access CHAR(1) DEFAULT 'P'
        )");

        $pdo->exec("INSERT INTO webcal_entry (cal_id, cal_name, cal_date, cal_type, cal_create_by) VALUES
            (1, 'Team Standup', 20260401, 'E', 'admin'),
            (2, 'Team Retrospective', 20260402, 'E', 'admin'),
            (3, 'Team Planning', 20260403, 'E', 'admin'),
            (4, 'Lunch Break', 20260404, 'E', 'admin'),
            (5, 'Team Offsite', 20260405, 'E', 'admin'),
            (6, 'Team Building', 20260406, 'E', 'admin')
        ");

        $factory = new CoreServiceFactory($pdo, 'test');
        $this->service = new SearchIndexService(new TenantAwarePdoProvider($factory->getPdo()));
    }

    public function testSuggestReturnsMatchingResults(): void
    {
        $results = $this->service->suggest('Team', 'admin', 5);

        $this->assertLessThanOrEqual(5, \count($results));
        foreach ($results as $r) {
            $this->assertStringContainsString('Team', $r['title']);
        }
    }

    public function testSuggestLimitsResults(): void
    {
        $results = $this->service->suggest('Team', 'admin', 3);
        $this->assertLessThanOrEqual(3, \count($results));
    }

    public function testSuggestReturnsEmptyForNoMatch(): void
    {
        $results = $this->service->suggest('zzzzz', 'admin', 5);
        $this->assertEmpty($results);
    }

    public function testSuggestFiltersByUser(): void
    {
        $results = $this->service->suggest('Team', 'bob', 5);
        $this->assertEmpty($results);
    }

    public function testSuggestResultHasRequiredFields(): void
    {
        $results = $this->service->suggest('Team', 'admin', 1);
        $this->assertNotEmpty($results);

        $this->assertArrayHasKey('id', $results[0]);
        $this->assertArrayHasKey('title', $results[0]);
        $this->assertArrayHasKey('start_date', $results[0]);
        $this->assertArrayHasKey('type', $results[0]);
    }
}
