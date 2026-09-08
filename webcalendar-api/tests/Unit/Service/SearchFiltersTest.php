<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\CoreServiceFactory;
use App\Service\SearchIndexService;
use App\Service\TenantAwarePdoProvider;
use PHPUnit\Framework\TestCase;

final class SearchFiltersTest extends TestCase
{
    private SearchIndexService $service;

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
        $pdo->exec('CREATE TABLE webcal_entry_categories (cal_id INTEGER, cat_id INTEGER)');
        $pdo->exec("CREATE TABLE webcal_entry_user (cal_id INTEGER, cal_login VARCHAR(60), cal_status CHAR(1) DEFAULT 'W')");

        $pdo->exec("INSERT INTO webcal_entry VALUES (1, 'January Meeting', '', 20260115, 0, 'E', 'admin', 60, 0, 0, 'P')");
        $pdo->exec("INSERT INTO webcal_entry VALUES (2, 'March Meeting', '', 20260315, 0, 'E', 'admin', 60, 0, 0, 'P')");
        $pdo->exec("INSERT INTO webcal_entry VALUES (3, 'Buy Supplies', '', 20260201, 0, 'T', 'admin', 0, 0, 0, 'P')");
        $pdo->exec('INSERT INTO webcal_entry_categories VALUES (1, 5)');
        $pdo->exec("INSERT INTO webcal_entry_user VALUES (2, 'alice', 'A')");

        $factory = new CoreServiceFactory($pdo, 'test');
        $this->service = new SearchIndexService(new TenantAwarePdoProvider($factory->getPdo()));
    }

    public function testFilterByDateRange(): void
    {
        $result = $this->service->search('Meeting', 'admin', null, 20, 0, [
            'start' => '20260201',
            'end' => '20260401',
        ]);
        $this->assertSame(1, $result['total']);
        $this->assertSame('March Meeting', $result['results'][0]['title']);
    }

    public function testFilterByCategory(): void
    {
        $result = $this->service->search('Meeting', 'admin', null, 20, 0, [
            'category_id' => '5',
        ]);
        $this->assertSame(1, $result['total']);
        $this->assertSame('January Meeting', $result['results'][0]['title']);
    }

    public function testFilterByParticipant(): void
    {
        $result = $this->service->search('Meeting', 'admin', null, 20, 0, [
            'participant' => 'alice',
        ]);
        $this->assertSame(1, $result['total']);
        $this->assertSame('March Meeting', $result['results'][0]['title']);
    }

    public function testFilterByType(): void
    {
        $result = $this->service->search('Buy', 'admin', 'task');
        $this->assertSame(1, $result['total']);
        $this->assertSame('Buy Supplies', $result['results'][0]['title']);
    }

    public function testCombinedFilters(): void
    {
        // Date range + type = only March events
        $result = $this->service->search('%', 'admin', 'event', 20, 0, [
            'start' => '20260301',
            'end' => '20260331',
        ]);
        $this->assertSame(1, $result['total']);
        $this->assertSame('March Meeting', $result['results'][0]['title']);
    }

    public function testNoMatchWithFilters(): void
    {
        $result = $this->service->search('Meeting', 'admin', null, 20, 0, [
            'category_id' => '999',
        ]);
        $this->assertSame(0, $result['total']);
    }
}
