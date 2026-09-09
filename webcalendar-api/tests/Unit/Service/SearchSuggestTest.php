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
    private \PDO $pdo;

    #[\Override]
    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo = $pdo;

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

    // ------------------------------------------------------- prefix and limit

    private function addEntry(int $id, string $title, int $date = 20260501): void
    {
        $this->pdo->prepare(
            'INSERT INTO webcal_entry (cal_id, cal_name, cal_date, cal_type, cal_create_by)
             VALUES (?, ?, ?, ?, ?)',
        )->execute([$id, $title, $date, 'E', 'admin']);
    }

    public function testSuggestionsAreAnchoredAtTheStartOfTheTitle(): void
    {
        // The bound value is `$prefix . '%'`. Dropping the wildcard demands an
        // exact title; dropping the prefix matches everything. Neither shows
        // up unless something in the table contains the prefix without
        // starting with it.
        $this->addEntry(50, 'The Team Meeting');

        $titles = array_column($this->service->suggest('Team', 'admin', 20), 'title');

        self::assertNotSame([], $titles);
        self::assertNotContains('The Team Meeting', $titles);

        foreach ($titles as $title) {
            self::assertStringStartsWith('Team', $title);
        }
    }

    public function testTheDefaultIsFiveSuggestions(): void
    {
        for ($i = 1; $i <= 8; $i++) {
            $this->addEntry(100 + $i, sprintf('Zebra %02d', $i), 20260500 + $i);
        }

        self::assertCount(5, $this->service->suggest('Zebra', 'admin'));
    }

    public function testAnExplicitLimitIsHonoured(): void
    {
        for ($i = 1; $i <= 8; $i++) {
            $this->addEntry(200 + $i, sprintf('Zebra %02d', $i), 20260500 + $i);
        }

        self::assertCount(2, $this->service->suggest('Zebra', 'admin', 2));
    }

    public function testMoreThanOneSuggestionComesBack(): void
    {
        // `return $results` reduced to its first element would still look
        // right to a test that only checks the shape of one row.
        $this->addEntry(301, 'Zebra One', 20260501);
        $this->addEntry(302, 'Zebra Two', 20260502);

        self::assertSame(
            ['Zebra Two', 'Zebra One'],
            array_column($this->service->suggest('Zebra', 'admin'), 'title'),
            'newest first, and both of them',
        );
    }

    public function testSuggestionsAreTypedTheSameWhicheverWayTheDriverReturnsColumns(): void
    {
        // SQLite hands back a native int for cal_id; MySQL stringifies it.
        $this->addEntry(401, 'Zebra Typed', 20260501);

        $suggestion = $this->service->suggest('Zebra Typed', 'admin')[0];

        self::assertIsInt($suggestion['id']);
        self::assertSame('20260501', $suggestion['start_date']);
        self::assertSame('E', $suggestion['type']);
    }

    public function testAPrefixIsMatchedAgainstDescriptionsToo(): void
    {
        // Two bound values, one per column. The description's wildcard is its
        // own concatenation and nothing reached it while every fixture
        // matched on the title.
        $this->pdo->prepare(
            'INSERT INTO webcal_entry (cal_id, cal_name, cal_description, cal_date, cal_type, cal_create_by)
             VALUES (?, ?, ?, ?, ?, ?)',
        )->execute([500, 'Nothing in the title', 'Zebra logistics discussion', 20260501, 'E', 'admin']);

        self::assertSame(
            ['Nothing in the title'],
            array_column($this->service->suggest('Zebra', 'admin'), 'title'),
        );
    }
}
