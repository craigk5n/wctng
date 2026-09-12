<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Api\ReportExportController;
use App\Security\WebCalendarUser;
use App\Service\ReportService;
use App\Service\TenantAwarePdoProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use WebCalendar\Core\Domain\Entity\User;

/**
 * Reports, as a file somebody downloads and opens in a spreadsheet.
 *
 * Nothing executed a line of it. Two things leave this controller that nothing
 * checked: the CSV, whose cells are opened by a program that reads some of them
 * as formulas, and the filename, which is built by interpolating two query
 * parameters into a quoted header value.
 */
final class ReportExportControllerTest extends TestCase
{
    private const NOW = '2026-03-15T10:00:00+00:00';

    private \PDO $pdo;

    #[\Override]
    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            "CREATE TABLE webcal_entry (
                cal_id INTEGER PRIMARY KEY, cal_name VARCHAR(200) DEFAULT '',
                cal_date INTEGER DEFAULT 0, cal_time INTEGER DEFAULT 0,
                cal_type CHAR(1) DEFAULT 'E', cal_create_by VARCHAR(60) DEFAULT ''
            )",
        );
        $this->pdo->exec('CREATE TABLE webcal_entry_categories (cal_id INTEGER, cat_id INTEGER)');
        $this->pdo->exec('CREATE TABLE webcal_categories (cat_id INTEGER PRIMARY KEY, cat_name VARCHAR(100), cat_owner VARCHAR(60))');
    }

    private function event(int $id, string $name, int $date, string $owner = 'alice', string $type = 'E'): void
    {
        $this->pdo->prepare(
            'INSERT INTO webcal_entry (cal_id, cal_name, cal_date, cal_time, cal_type, cal_create_by)
             VALUES (?, ?, ?, 90000, ?, ?)',
        )->execute([$id, $name, $date, $type, $owner]);
    }

    private function category(int $id, string $name, int $eventId): void
    {
        $this->pdo->prepare('INSERT INTO webcal_categories VALUES (?, ?, ?)')->execute([$id, $name, 'alice']);
        $this->pdo->prepare('INSERT INTO webcal_entry_categories VALUES (?, ?)')->execute([$eventId, $id]);
    }

    private function controller(): ReportExportController
    {
        return new ReportExportController(
            new ReportService(new TenantAwarePdoProvider($this->pdo), new MockClock(self::NOW)),
            new MockClock(self::NOW),
        );
    }

    private static function user(string $login = 'alice'): WebCalendarUser
    {
        return new WebCalendarUser(new User($login, 'A', 'B', "{$login}@x.com", false, true), null);
    }

    private static function request(string $query = ''): Request
    {
        return Request::create('/api/v2/reports/export/activity?' . $query);
    }

    private function export(string $type, string $query = ''): Response
    {
        return $this->controller()->export($type, self::request($query), self::user());
    }

    private static function body(Response $response): string
    {
        $body = $response->getContent();
        self::assertIsString($body);

        return $body;
    }

    // ------------------------------------------------------------------ guards

    public function testItNeedsASession(): void
    {
        $this->assertSame(401, $this->controller()->export('activity', self::request(), null)->getStatusCode());
    }

    #[DataProvider('reportTypes')]
    public function testEachReportTypeComesBackAsACsvFile(string $type): void
    {
        $response = $this->export($type, 'start=20260301&end=20260331');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/csv', $response->headers->get('Content-Type'));
    }

    /** @return iterable<string, array{string}> */
    public static function reportTypes(): iterable
    {
        yield 'activity' => ['activity'];
        yield 'categories' => ['categories'];
        yield 'upcoming' => ['upcoming'];
    }

    #[DataProvider('typesThatAreNotReports')]
    public function testAReportTypeNobodyOffersIsRefused(string $type): void
    {
        $response = $this->export($type, 'start=20260301&end=20260331');

        $this->assertSame(400, $response->getStatusCode());
    }

    /** @return iterable<string, array{string}> */
    public static function typesThatAreNotReports(): iterable
    {
        yield 'made up' => ['payroll'];
        yield 'the wrong case' => ['Activity'];
        yield 'empty' => [''];
    }

    // ------------------------------------------------------------ what is in it

    public function testTheActivityReportCountsEventsByDay(): void
    {
        $this->event(1, 'Standup', 20260310);
        $this->event(2, 'Review', 20260310);
        $this->event(3, 'Retro', 20260311);
        $this->event(4, 'Not mine', 20260310, 'bob');

        $csv = self::body($this->export('activity', 'start=20260301&end=20260331'));

        $this->assertSame("Date,Event Count\r\n20260310,2\r\n20260311,1", $csv);
    }

    public function testTheCategoryReportCountsEventsByCategory(): void
    {
        $this->event(1, 'Standup', 20260310);
        $this->category(1, 'Work', 1);

        $csv = self::body($this->export('categories', 'start=20260301&end=20260331'));

        $this->assertSame("Category,Event Count\r\n\"Work\",1", $csv);
    }

    public function testTheUpcomingReportListsWhatIsComing(): void
    {
        $this->event(1, 'Kickoff', 20260320, 'alice', 'E');

        $csv = self::body($this->export('upcoming'));

        $this->assertSame("ID,Title,Date,Type\r\n1,\"Kickoff\",20260320,E", $csv);
    }

    public function testTheUpcomingReportReachesThirtyDaysAndNoFurther(): void
    {
        // Today is the 15th of March, so the thirtieth day is the 14th of
        // April and the day after it is outside the report.
        $this->event(1, 'Last day in', 20260414);
        $this->event(2, 'First day out', 20260415);

        $csv = self::body($this->export('upcoming'));

        $this->assertStringContainsString('Last day in', $csv);
        $this->assertStringNotContainsString('First day out', $csv);
    }

    public function testARangeOfASingleDayIsFine(): void
    {
        $this->event(1, 'Standup', 20260310);

        $response = $this->export('activity', 'start=20260310&end=20260310');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('20260310,1', self::body($response));
    }

    public function testAQuoteInATitleIsDoubledRatherThanEndingTheField(): void
    {
        $this->event(1, 'The "big" one', 20260320);

        $csv = self::body($this->export('upcoming'));

        $this->assertStringContainsString('"The ""big"" one"', $csv);
    }

    public function testAnEmptyReportIsStillAFileWithAHeaderRow(): void
    {
        $this->assertSame('Date,Event Count', self::body($this->export('activity', 'start=20260301&end=20260331')));
    }

    /**
     * A spreadsheet reads a cell beginning with one of these as a formula, not
     * as text -- which is the whole risk of handing somebody a CSV. The titles
     * in it are not all the reader's own: the public booking route writes one
     * from an anonymous request, and a category can be somebody else's.
     */
    #[DataProvider('cellsASpreadsheetWouldRun')]
    public function testATitleIsNeverHandedOverAsAFormula(string $title): void
    {
        $this->event(1, $title, 20260320);

        $csv = self::body($this->export('upcoming'));

        $this->assertMatchesRegularExpression('/^1,"\'/m', $csv, 'the cell has to start as text');
        // Un-double the CSV quoting to read the cell back as it was written.
        $this->assertStringContainsString("'" . $title, str_replace('""', '"', $csv), 'and still carry what it said');
    }

    #[DataProvider('cellsASpreadsheetWouldRun')]
    public function testACategoryNameIsNeverHandedOverAsAFormula(string $name): void
    {
        $this->event(1, 'Standup', 20260310);
        $this->category(1, $name, 1);

        $csv = self::body($this->export('categories', 'start=20260301&end=20260331'));

        $this->assertMatchesRegularExpression('/^"\'/m', $csv);
        $this->assertStringContainsString("'" . $name, str_replace('""', '"', $csv));
    }

    /** @return iterable<string, array{string}> */
    public static function cellsASpreadsheetWouldRun(): iterable
    {
        yield 'an equals' => ['=1+1'];
        yield 'a hyperlink' => ['=HYPERLINK("http://evil.example","Click")'];
        yield 'a plus' => ['+1+1'];
        yield 'a minus' => ['-1+1'];
        yield 'an at sign' => ['@SUM(A1:A9)'];
        yield 'a tab first' => ["\t=1+1"];
        yield 'a carriage return first' => ["\r=1+1"];
    }

    public function testAnOrdinaryTitleIsNotDressedUp(): void
    {
        $this->event(1, 'Quarter Kickoff', 20260320);

        $this->assertStringContainsString('1,"Quarter Kickoff",', self::body($this->export('upcoming')));
    }

    // ------------------------------------------------------------ the filename

    public function testTheFileIsNamedAfterTheReportAndItsRange(): void
    {
        $response = $this->export('activity', 'start=20260301&end=20260331');

        $this->assertSame(
            'attachment; filename="report-activity-20260301-to-20260331.csv"',
            $response->headers->get('Content-Disposition'),
        );
    }

    public function testWithNoRangeItReportsTheLastThirtyDays(): void
    {
        $response = $this->export('activity');

        $this->assertSame(
            'attachment; filename="report-activity-20260213-to-20260315.csv"',
            $response->headers->get('Content-Disposition'),
        );
    }

    /**
     * Both parameters are interpolated into a quoted header value and were
     * never looked at, so a quote in one of them ended the quoted string and
     * a newline went into the header as written.
     */
    #[DataProvider('rangesThatAreNotDates')]
    public function testARangeThatIsNotOneIsRefused(string $query): void
    {
        $response = $this->export('activity', $query);

        $this->assertSame(400, $response->getStatusCode());
    }

    /** @return iterable<string, array{string}> */
    public static function rangesThatAreNotDates(): iterable
    {
        yield 'words' => ['start=yesterday&end=20260331'];
        yield 'the wrong shape' => ['start=2026-03-01&end=2026-03-31'];
        yield 'the 31st of February' => ['start=20260231&end=20260331'];
        yield 'the 13th month' => ['start=20260301&end=20261345'];
        yield 'ends before it starts' => ['start=20260331&end=20260301'];
        yield 'a quote in the start' => ['start=' . urlencode('2026"0301') . '&end=20260331'];
        yield 'a newline in the end' => ['start=20260301&end=' . urlencode("20260331\r\nX-Injected: 1")];
    }

    #[DataProvider('halfGivenRanges')]
    public function testEachEndOfTheRangeDefaultsOnItsOwn(string $query, string $expected): void
    {
        // One end given and the other left out is a narrower question, not a
        // malformed one.
        $response = $this->export('activity', $query);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($expected, $response->headers->get('Content-Disposition'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function halfGivenRanges(): iterable
    {
        yield 'only a start' => [
            'start=20260301',
            'attachment; filename="report-activity-20260301-to-20260315.csv"',
        ];
        yield 'only an end' => [
            'end=20260331',
            'attachment; filename="report-activity-20260213-to-20260331.csv"',
        ];
    }

    public function testNothingUnexpectedReachesTheHeader(): void
    {
        // Whatever a refused range was, it must not have been written out.
        $response = $this->export('activity', 'start=' . urlencode("2026\r\nX-Injected: 1") . '&end=20260331');

        $this->assertSame(400, $response->getStatusCode());
        $this->assertNull($response->headers->get('X-Injected'));
        $this->assertStringNotContainsString('X-Injected', (string) $response->headers->get('Content-Disposition'));
    }
}
