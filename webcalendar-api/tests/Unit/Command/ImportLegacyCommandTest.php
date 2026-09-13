<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\ImportLegacyCommand;
use App\Service\CoreServiceFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Importing a legacy WebCalendar database.
 *
 * Nothing had executed a line of it. It takes one option, opens a second
 * database with it, and writes the contents of that database into this one --
 * so the two things worth holding it to are that --dry-run really writes
 * nothing, and that a DSN it cannot use is refused rather than half-used.
 */
final class ImportLegacyCommandTest extends TestCase
{
    private \PDO $destination;
    private CoreServiceFactory $factory;
    private string $legacyPath;

    #[\Override]
    protected function setUp(): void
    {
        $this->destination = new \PDO('sqlite::memory:');
        $this->destination->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->loadCoreSchema($this->destination);

        $this->factory = new CoreServiceFactory($this->destination, 'secret-for-tests');

        $this->legacyPath = sys_get_temp_dir() . '/wctng-legacy-' . bin2hex(random_bytes(6)) . '.db';
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (is_file($this->legacyPath)) {
            unlink($this->legacyPath);
        }
    }

    private function loadCoreSchema(\PDO $pdo): void
    {
        $path = realpath(__DIR__ . '/../../../vendor/craigk5n/webcalendar-core/src/Infrastructure/Persistence/sqlite-schema.sql');
        self::assertIsString($path);
        $schema = file_get_contents($path);
        self::assertIsString($schema);
        /** @var string[] $stmts */
        $stmts = preg_split('/;\s*\n/', (string) preg_replace('/--[^\n]*/', '', $schema)) ?? [];
        foreach ($stmts as $statement) {
            if (trim($statement) !== '') {
                try {
                    $pdo->exec(trim($statement));
                } catch (\PDOException) {
                }
            }
        }
    }

    /**
     * A legacy database with the two tables the importer insists on, plus one
     * user and one event to carry across.
     */
    private function writeLegacyDatabase(bool $withUserTable = true): void
    {
        $legacy = new \PDO('sqlite:' . $this->legacyPath);
        $legacy->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        if ($withUserTable) {
            $legacy->exec(
                'CREATE TABLE webcal_user (
                    cal_login TEXT, cal_lastname TEXT, cal_firstname TEXT,
                    cal_email TEXT, cal_is_admin TEXT, cal_enabled TEXT
                )',
            );
            $legacy->exec(
                'INSERT INTO webcal_user VALUES (\'jbloggs\', \'Bloggs\', \'Joe\', \'joe@old.example\', \'N\', \'Y\')',
            );
        }

        $legacy->exec(
            'CREATE TABLE webcal_entry (
                cal_id INTEGER, cal_name TEXT, cal_date INTEGER, cal_time INTEGER,
                cal_create_by TEXT, cal_duration INTEGER, cal_access TEXT, cal_type TEXT
            )',
        );
        $legacy->exec(
            "INSERT INTO webcal_entry VALUES (1, 'Quarterly review', 20260601, 100000, 'jbloggs', 60, 'P', 'E')",
        );

        // One of each of the four things the closing total adds up, so that
        // dropping any one term from that sum changes the number reported.
        $legacy->exec('CREATE TABLE webcal_categories (cat_id INTEGER, cat_name TEXT, cat_owner TEXT, cat_color TEXT)');
        $legacy->exec("INSERT INTO webcal_categories VALUES (1, 'Meetings', NULL, '#3788d8')");

        $legacy->exec('CREATE TABLE webcal_user_pref (cal_login TEXT, cal_setting TEXT, cal_value TEXT)');
        $legacy->exec("INSERT INTO webcal_user_pref VALUES ('jbloggs', 'TIMEZONE', 'America/New_York')");
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new ImportLegacyCommand($this->factory));
    }

    private function destinationUsers(): int
    {
        return (int) ($this->destination->query('SELECT COUNT(*) FROM webcal_user')?->fetchColumn() ?: 0);
    }

    private function destinationEvents(): int
    {
        return (int) ($this->destination->query('SELECT COUNT(*) FROM webcal_entry')?->fetchColumn() ?: 0);
    }

    // ------------------------------------------------------------- refusals

    public function testWithoutADsnItSaysWhatOneLooksLike(): void
    {
        $tester = $this->tester();

        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('--dsn', $tester->getDisplay());
        // And it stopped there rather than going on to open something.
        self::assertStringNotContainsString('Connecting to legacy database', $tester->getDisplay());
    }

    /** @return iterable<string, array{string}> */
    public static function dsnsItCannotOpen(): iterable
    {
        yield 'no scheme' => ['just-a-name'];
        yield 'a bare path' => ['/var/lib/legacy.db'];
        yield 'a scheme nobody supports' => ['oracle://user:pw@host/db'];
    }

    #[DataProvider('dsnsItCannotOpen')]
    public function testADsnItCannotOpenStopsBeforeAnythingIsWritten(string $dsn): void
    {
        $tester = $this->tester();

        $tester->execute(['--dsn' => $dsn]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Failed to connect', $tester->getDisplay());
        // Carrying the prefix without the reason tells an operator nothing.
        self::assertMatchesRegularExpression('/Failed to connect: \S/', $tester->getDisplay());
        self::assertSame(0, $this->destinationUsers(), 'it wrote something on its way out');
    }

    public function testADatabaseThatIsNotAWebCalendarOneIsRefused(): void
    {
        // No webcal_user table: the importer checks for it before writing.
        $this->writeLegacyDatabase(withUserTable: false);
        $tester = $this->tester();

        $tester->execute(['--dsn' => "sqlite:///{$this->legacyPath}"]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Import failed', $tester->getDisplay());
        // Which table was missing is the only useful part of that sentence,
        // and it has to come after the prefix rather than merely be present.
        self::assertMatchesRegularExpression('/Import failed:.*webcal_user/s', $tester->getDisplay());
        self::assertSame(0, $this->destinationUsers());
    }

    // -------------------------------------------------------------- reading

    public function testTheDocumentedSqliteSpellingIsAcceptedAsWritten(): void
    {
        // sqlite:///path is what the --dsn help text and the failure message
        // both tell an operator to type, and parse_url rejects it outright.
        $this->writeLegacyDatabase();
        $tester = $this->tester();

        $tester->execute(['--dsn' => "sqlite:///{$this->legacyPath}"]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Connected to legacy database', $tester->getDisplay());
    }

    public function testItNarratesEachStageItReaches(): void
    {
        // A long import that stops says how far it got only through these.
        $this->writeLegacyDatabase();
        $tester = $this->tester();

        $tester->execute(['--dsn' => "sqlite:///{$this->legacyPath}"]);
        $display = $tester->getDisplay();

        foreach (['Connecting to legacy database', 'Importing data', 'Import Summary'] as $stage) {
            self::assertStringContainsString($stage, $display, "the {$stage} stage went unannounced");
        }
    }

    public function testAnImportCarriesTheUsersAndEventsAcross(): void
    {
        $this->writeLegacyDatabase();
        $tester = $this->tester();

        $tester->execute(['--dsn' => "sqlite:///{$this->legacyPath}"]);

        self::assertSame(1, $this->destinationUsers());
        self::assertSame(1, $this->destinationEvents());
        self::assertStringContainsString('Import complete', $tester->getDisplay());
    }

    public function testTheSummaryNamesEveryKindOfThingItImported(): void
    {
        $this->writeLegacyDatabase();
        $tester = $this->tester();

        $tester->execute(['--dsn' => "sqlite:///{$this->legacyPath}"]);
        $display = $tester->getDisplay();

        foreach (['Users', 'Events', 'Categories', 'Participants', 'Preferences'] as $row) {
            self::assertStringContainsString($row, $display, "the {$row} row went missing from the summary");
        }
        foreach (['Entity', 'Imported', 'Skipped', 'Errors'] as $column) {
            self::assertStringContainsString($column, $display, "the {$column} column went missing");
        }
    }

    public function testItReportsWhichLegacyTablesItRecognised(): void
    {
        $this->writeLegacyDatabase();
        $tester = $this->tester();

        $tester->execute(['--dsn' => "sqlite:///{$this->legacyPath}"]);
        $display = $tester->getDisplay();

        self::assertStringContainsString('Schema Detection', $display);
        self::assertStringContainsString('webcal_entry', $display);
    }

    public function testItSaysThatImportedUsersCannotLogInYet(): void
    {
        // Every imported user gets a random password, so nobody can sign in
        // until they reset. An operator who misses that has locked out the
        // people they just migrated.
        $this->writeLegacyDatabase();
        $tester = $this->tester();

        $tester->execute(['--dsn' => "sqlite:///{$this->legacyPath}"]);

        self::assertStringContainsString('reset their passwords', $tester->getDisplay());
    }

    // -------------------------------------------------------------- dry run

    public function testADryRunWritesNothingAtAll(): void
    {
        $this->writeLegacyDatabase();
        $tester = $this->tester();

        $tester->execute(['--dsn' => "sqlite:///{$this->legacyPath}", '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(0, $this->destinationUsers(), 'a dry run created a user');
        self::assertSame(0, $this->destinationEvents(), 'a dry run created an event');
    }

    public function testADryRunSaysSoBeforeAndAfter(): void
    {
        $this->writeLegacyDatabase();
        $tester = $this->tester();

        $tester->execute(['--dsn' => "sqlite:///{$this->legacyPath}", '--dry-run' => true]);
        $display = $tester->getDisplay();

        self::assertStringContainsString('no data will be written', $display);
        self::assertStringContainsString('DRY RUN complete', $display);
        self::assertStringNotContainsString('Import complete', $display);
    }

    public function testADryRunStillCountsWhatItWouldHaveDone(): void
    {
        $this->writeLegacyDatabase();
        $tester = $this->tester();

        $tester->execute(['--dsn' => "sqlite:///{$this->legacyPath}", '--dry-run' => true]);

        // One of each: users, events, categories and preferences all count
        // towards the total, so dropping any term changes this number.
        self::assertStringContainsString('4 items would be imported', $tester->getDisplay());
    }
}
