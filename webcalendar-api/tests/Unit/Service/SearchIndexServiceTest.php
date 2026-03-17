<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\CoreServiceFactory;
use App\Service\SearchIndexService;
use PHPUnit\Framework\TestCase;

final class SearchIndexServiceTest extends TestCase
{
    private \PDO $pdo;
    private SearchIndexService $service;

    #[\Override]
    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec("CREATE TABLE webcal_entry (
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

        $this->pdo->exec("INSERT INTO webcal_entry (cal_id, cal_name, cal_description, cal_date, cal_type, cal_create_by) VALUES
            (1, 'Team Meeting', 'Discuss quarterly goals', 20260401, 'E', 'admin'),
            (2, 'Buy Groceries', 'Milk, eggs, bread', 20260402, 'T', 'admin'),
            (3, 'Project Log', 'Deployed v2.0 to production', 20260403, 'J', 'admin'),
            (4, 'Other User Event', 'Not mine', 20260404, 'E', 'bob')
        ");

        $factory = new CoreServiceFactory($this->pdo, 'test');
        $this->service = new SearchIndexService($factory);
    }

    public function testSearchByTitle(): void
    {
        $result = $this->service->search('Meeting', 'admin');
        $this->assertSame(1, $result['total']);
        $this->assertSame('Team Meeting', $result['results'][0]['title']);
    }

    public function testSearchByDescription(): void
    {
        $result = $this->service->search('quarterly', 'admin');
        $this->assertSame(1, $result['total']);
        $this->assertSame('Team Meeting', $result['results'][0]['title']);
    }

    public function testSearchFiltersByType(): void
    {
        $result = $this->service->search('', 'admin', 'task');
        // Should only find tasks
        foreach ($result['results'] as $r) {
            $this->assertContains($r['type'], ['T', 'N']);
        }
    }

    public function testSearchFiltersByUser(): void
    {
        // Bob's event should not appear for admin
        $adminResult = $this->service->search('Other', 'admin');
        $this->assertSame(0, $adminResult['total']);

        // But should appear for bob
        $bobResult = $this->service->search('Other', 'bob');
        $this->assertSame(1, $bobResult['total']);
    }

    public function testPagination(): void
    {
        $result = $this->service->search('%', 'admin', null, 2, 0);
        $this->assertLessThanOrEqual(2, \count($result['results']));
        $this->assertSame(3, $result['total']); // 3 admin entries
    }

    public function testSnippetContainsHighlight(): void
    {
        $result = $this->service->search('quarterly', 'admin');
        $this->assertNotEmpty($result['results']);
        $this->assertStringContainsString('<mark>', $result['results'][0]['snippet']);
    }

    public function testSnippetFallbackForNoMatch(): void
    {
        // generateSnippet via reflection
        $factory = new CoreServiceFactory($this->pdo, 'test');
        $service = new SearchIndexService($factory);
        $method = new \ReflectionMethod($service, 'generateSnippet');

        $snippet = $method->invoke($service, 'Title', 'No matching text here', 'zzzzz');
        $this->assertNotEmpty($snippet);
        $this->assertStringNotContainsString('<mark>', $snippet);
    }
}
