<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\CoreServiceFactory;
use App\Service\SearchIndexService;
use App\Service\TenantAwarePdoProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SearchFiltersTest extends TestCase
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

    // -------------------------------------------- the filters that were not

    public function testAnUnknownTypeIsIgnoredRatherThanApplied(): void
    {
        // The guard is `$type !== null && isset(TYPE_MAP[$type])`. With an
        // `||` an unrecognised type reaches TYPE_MAP anyway and the lookup
        // has nothing to return, so the request dies instead of simply not
        // filtering.
        $result = $this->service->search('Meeting', 'admin', 'not-a-type');

        self::assertSame(2, $result['total'], 'an unknown type filters nothing out');
    }

    public function testANullTypeFiltersNothing(): void
    {
        $result = $this->service->search('Meeting', 'admin', null);

        self::assertSame(2, $result['total']);
    }

    public function testTheEndOfTheDateRangeExcludesWhatComesAfterIt(): void
    {
        // The earlier test set both ends but had nothing past the end to
        // exclude, so dropping the end filter changed no answer.
        $result = $this->service->search('Meeting', 'admin', null, 20, 0, [
            'start' => '20260101',
            'end' => '20260201',
        ]);

        self::assertSame(1, $result['total']);
        self::assertSame('January Meeting', $result['results'][0]['title']);
    }

    public function testTheStartOfTheDateRangeExcludesWhatComesBefore(): void
    {
        $result = $this->service->search('Meeting', 'admin', null, 20, 0, [
            'start' => '20260201',
        ]);

        self::assertSame(1, $result['total']);
        self::assertSame('March Meeting', $result['results'][0]['title']);
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function emptyFilterValues(): iterable
    {
        yield 'blank start' => [['start' => '']];
        yield 'blank end' => [['end' => '']];
        yield 'blank category' => [['category_id' => '']];
        yield 'blank participant' => [['participant' => '']];
    }

    /** @param array<string, string> $filters */
    #[DataProvider('emptyFilterValues')]
    public function testABlankFilterValueIsNotAFilter(array $filters): void
    {
        // An unfilled form field arrives as '' rather than being absent, and
        // must not narrow the search to nothing.
        $result = $this->service->search('Meeting', 'admin', null, 20, 0, $filters);

        self::assertSame(2, $result['total']);
    }

    public function testCategoryAndParticipantFiltersCanBothApplyAtOnce(): void
    {
        // Each appends its own INNER JOIN. Assignment in place of appending
        // drops whichever came first, and the WHERE clause then references a
        // table that is no longer joined.
        $this->pdo->exec('INSERT INTO webcal_entry_categories VALUES (2, 5)');

        $both = $this->service->search('Meeting', 'admin', null, 20, 0, [
            'category_id' => '5',
            'participant' => 'alice',
        ]);

        self::assertSame(1, $both['total'], 'only the March meeting is both in category 5 and has alice');
        self::assertSame('March Meeting', $both['results'][0]['title']);
    }

    public function testTheParticipantJoinDoesNotDropTheCategoryOne(): void
    {
        // The same pair, but where the category alone would match a different
        // row: if the category join were discarded, this would come back with
        // January's meeting too.
        $this->pdo->exec("INSERT INTO webcal_entry_user VALUES (1, 'alice', 'A')");

        $result = $this->service->search('Meeting', 'admin', null, 20, 0, [
            'category_id' => '5',
            'participant' => 'alice',
        ]);

        self::assertSame(['January Meeting'], array_column($result['results'], 'title'));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function typeFiltersAndTheirRepeatingHalf(): iterable
    {
        // Each filter covers a pair: the plain entry and its repeating
        // variant. Only E, T, J and N appear anywhere in these tests, so
        // dropping M or O from the map would silently hide every repeating
        // event or journal from a filtered search, and nothing would say so --
        // the map is a constant, which no mutation touches either.
        yield 'events include repeating events' => ['event', 'E', 'M'];
        yield 'tasks include repeating tasks' => ['task', 'T', 'N'];
        yield 'journals include repeating journals' => ['journal', 'J', 'O'];
    }

    #[DataProvider('typeFiltersAndTheirRepeatingHalf')]
    public function testATypeFilterFindsBothHalvesOfItsPair(string $filter, string $plain, string $repeating): void
    {
        $this->pdo->exec('DELETE FROM webcal_entry');
        $this->pdo->exec("INSERT INTO webcal_entry VALUES (10, 'Standup once', '', 20260401, 0, '{$plain}', 'admin', 30, 0, 0, 'P')");
        $this->pdo->exec("INSERT INTO webcal_entry VALUES (11, 'Standup weekly', '', 20260402, 0, '{$repeating}', 'admin', 30, 0, 0, 'P')");
        // An entry of another kind entirely, which the filter must exclude.
        $other = $plain === 'E' ? 'T' : 'E';
        $this->pdo->exec("INSERT INTO webcal_entry VALUES (12, 'Standup elsewhere', '', 20260403, 0, '{$other}', 'admin', 0, 0, 0, 'P')");

        $result = $this->service->search('Standup', 'admin', $filter);

        $types = array_map(static fn(array $r): mixed => $r['type'], $result['results']);
        sort($types);
        $expected = [$plain, $repeating];
        sort($expected);

        self::assertSame($expected, $types);
        self::assertSame(2, $result['total']);
    }
}
