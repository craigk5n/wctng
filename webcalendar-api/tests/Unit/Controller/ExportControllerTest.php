<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Api\ExportController;
use App\Security\WebCalendarUser;
use App\Service\CoreServiceFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;

/**
 * The calendar, as a file somebody downloads.
 *
 * The Unit suite never executed a line of this controller, so nothing checked
 * either of the two things that leave it: the window the export covers, read
 * from two query parameters by a third private copy of the date parser, and
 * the filename, built by interpolating those same two parameters into a
 * quoted header value.
 */
final class ExportControllerTest extends TestCase
{
    private CoreServiceFactory $factory;
    private ExportController $controller;

    #[\Override]
    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $schemaPath = realpath(__DIR__ . '/../../../vendor/craigk5n/webcalendar-core/src/Infrastructure/Persistence/sqlite-schema.sql');
        self::assertIsString($schemaPath);
        $schema = file_get_contents($schemaPath);
        self::assertIsString($schema);
        /** @var string[] $stmts */
        $stmts = preg_split('/;\s*\n/', (string) preg_replace('/--[^\n]*/', '', $schema)) ?? [];
        foreach ($stmts as $s) {
            if (trim($s) !== '') {
                try {
                    $pdo->exec(trim($s));
                } catch (\PDOException) {
                }
            }
        }

        $this->factory = new CoreServiceFactory($pdo, 'test_secret');
        $this->factory->getUserService()->createUser(self::coreUser(), self::coreUser());

        $this->controller = new ExportController(
            $this->factory->getEventService(),
            $this->factory->getExportService(),
        );
    }

    private static function coreUser(string $login = 'alice'): User
    {
        return new User($login, 'A', 'B', "{$login}@x.com", true, true);
    }

    private function seed(string $title, string $date, string $time = '100000'): void
    {
        $start = \DateTimeImmutable::createFromFormat('Ymd His', $date . ' ' . $time);
        self::assertInstanceOf(\DateTimeImmutable::class, $start);

        $this->factory->getEventService()->createEvent(
            new Event(
                id: new EventId(0),
                uid: uniqid('exp-', true) . '@webcalendar',
                name: $title,
                description: '',
                location: '',
                start: $start,
                duration: 60,
                createdBy: 'alice',
                type: EventType::EVENT,
                access: AccessLevel::PUBLIC,
            ),
            self::coreUser(),
        );
    }

    private function export(string $query, bool $authenticated = true): Response
    {
        return $this->controller->export(
            Request::create('/api/v2/export?' . $query),
            $authenticated ? new WebCalendarUser(self::coreUser(), null) : null,
        );
    }

    private static function errorMessage(Response $response): string
    {
        /** @var array{error?: array{message?: string}} $decoded */
        $decoded = json_decode(self::body($response), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded['error']['message'] ?? '';
    }

    private static function body(Response $response): string
    {
        $body = $response->getContent();
        self::assertIsString($body);

        return $body;
    }

    // ------------------------------------------------------------ the basics

    public function testAnAnonymousCallerGetsNothing(): void
    {
        $response = $this->export('start=20260401&end=20260601', authenticated: false);

        self::assertSame(401, $response->getStatusCode());
    }

    /** @return iterable<string, array{string}> */
    public static function windowsWithSomethingMissing(): iterable
    {
        yield 'neither' => [''];
        yield 'no end' => ['start=20260401'];
        yield 'no start' => ['end=20260601'];
        yield 'both present but empty' => ['start=&end='];
    }

    /**
     * Both the status and the message matter. An empty parameter falls through
     * to the date parser and is refused there too, so the status alone cannot
     * tell "you left it out" from "that is not a date" -- and the caller is
     * told which one it is.
     */
    #[DataProvider('windowsWithSomethingMissing')]
    public function testAWindowHasToNameBothEnds(string $query): void
    {
        $response = $this->export($query);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(
            'Missing required query params: start, end (YYYYMMDD)',
            self::errorMessage($response),
        );
    }

    public function testTheExportCarriesTheEventsInTheWindow(): void
    {
        $this->seed('In the window', '20260501');
        $this->seed('Outside it', '20260901');

        $response = $this->export('start=20260401&end=20260601');
        $body = self::body($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/calendar; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertStringContainsString('BEGIN:VCALENDAR', $body);
        self::assertStringContainsString('In the window', $body);
        self::assertStringNotContainsString('Outside it', $body);
    }

    public function testBothEndsOfTheWindowAreInIt(): void
    {
        // The range ends at midnight on the closing day, which the repository
        // reads as the whole of that day because it compares cal_date only.
        $this->seed('On the opening day', '20260401');
        $this->seed('On the closing day', '20260601', '235900');

        $body = self::body($this->export('start=20260401&end=20260601'));

        self::assertStringContainsString('On the opening day', $body);
        self::assertStringContainsString('On the closing day', $body);
    }

    // ---------------------------------------------- dates that are not dates

    /** @return iterable<string, array{string}> */
    public static function datesThatAreNotDates(): iterable
    {
        yield 'the 31st of February' => ['20260231'];  // read as 3 March
        yield 'the 13th month' => ['20261345'];        // read as 14 Feb the year after
        yield 'month zero' => ['20260012'];            // read as 12 Dec the year BEFORE
        yield 'day zero' => ['20260900'];              // read as 31 August
    }

    #[DataProvider('datesThatAreNotDates')]
    public function testAStartThatIsNotADateIsRefused(string $date): void
    {
        $response = $this->export("start={$date}&end=20261231");

        self::assertSame(400, $response->getStatusCode(), $date . ' was accepted');
        self::assertSame('Invalid date format', self::errorMessage($response));
    }

    #[DataProvider('datesThatAreNotDates')]
    public function testAnEndThatIsNotADateIsRefused(string $date): void
    {
        self::assertSame(400, $this->export("start=20250101&end={$date}")->getStatusCode(), $date . ' was accepted');
    }

    public function testARolledDateDoesNotExportADifferentMonthUnderTheNameAsked(): void
    {
        // The filename echoes the parameters verbatim, so a date that rolls
        // hands back webcalendar-20260231-to-20260231.ics holding the 3rd of
        // March -- the day asked for on the tin, a different one inside.
        $this->seed('Third of March', '20260303');

        $response = $this->export('start=20260231&end=20260231');

        self::assertSame(400, $response->getStatusCode());
        self::assertStringNotContainsString('Third of March', self::body($response));
    }

    public function testAWindowThatRunsBackwardsIsRefusedRatherThanThrown(): void
    {
        // DateRange throws when start is after end, and nothing here catches
        // it, so the caller gets a 500 out of an endpoint that answers in JSON.
        $response = $this->export('start=20261231&end=20260101');

        self::assertSame(400, $response->getStatusCode());
    }

    public function testAWindowOfOneDayIsNotAWindowThatRunsBackwards(): void
    {
        $this->seed('The only day', '20260501');

        $response = $this->export('start=20260501&end=20260501');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('The only day', self::body($response));
    }

    // ------------------------------------------------------------- the file

    public function testTheFilenameNamesTheWindow(): void
    {
        $response = $this->export('start=20260401&end=20260601');

        self::assertSame(
            'attachment; filename="webcalendar-20260401-to-20260601.ics"',
            $response->headers->get('Content-Disposition'),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function parametersThatWouldBreakOutOfTheHeader(): iterable
    {
        yield 'a quote and a second filename' => ['2026"; filename="owned.ics'];
        yield 'a carriage return and a new header' => ["20260401\r\nX-Injected: yes"];
        yield 'a bare newline' => ["20260401\nX-Injected: yes"];
        yield 'a path' => ['../../etc/passwd'];
    }

    /**
     * The header is only safe because the date parser refuses anything that is
     * not eight digits before the parameter is ever interpolated. That is a
     * property of a guard written for another purpose, so it is pinned here:
     * loosening the parser would open the header.
     */
    #[DataProvider('parametersThatWouldBreakOutOfTheHeader')]
    public function testNothingReachesTheHeaderThatCouldBreakOutOfIt(string $param): void
    {
        $response = $this->export('start=' . rawurlencode($param) . '&end=20260601');

        self::assertSame(400, $response->getStatusCode());

        $disposition = (string) $response->headers->get('Content-Disposition');
        self::assertStringNotContainsString('X-Injected', $disposition);
        self::assertDoesNotMatchRegularExpression('/[\r\n]/', $disposition);
    }
}
