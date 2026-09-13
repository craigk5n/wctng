<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\SeedTestDataCommand;
use App\Security\PasswordHasher;
use App\Service\CoreServiceFactory;
use App\Service\TenantAwarePdoProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The performance-data seeder, and the delete that undoes it.
 *
 * Nothing had executed a line of it. It writes users, events and categories
 * in bulk, and --cleanup takes them out again with five DELETE statements
 * matched on a login prefix -- which is the part worth being careful about,
 * because the database it is pointed at is whichever one the application is
 * configured for.
 */
final class SeedTestDataCommandTest extends TestCase
{
    private const NOW = '2026-06-15T09:00:00+00:00';

    private \PDO $pdo;
    private CoreServiceFactory $factory;

    #[\Override]
    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $path = realpath(__DIR__ . '/../../../vendor/craigk5n/webcalendar-core/src/Infrastructure/Persistence/sqlite-schema.sql');
        self::assertIsString($path);
        $schema = file_get_contents($path);
        self::assertIsString($schema);
        /** @var string[] $stmts */
        $stmts = preg_split('/;\s*\n/', (string) preg_replace('/--[^\n]*/', '', $schema)) ?? [];
        foreach ($stmts as $statement) {
            if (trim($statement) !== '') {
                try {
                    $this->pdo->exec(trim($statement));
                } catch (\PDOException) {
                }
            }
        }

        $this->factory = new CoreServiceFactory($this->pdo, 'secret-for-tests');
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new SeedTestDataCommand(
            new TenantAwarePdoProvider($this->pdo),
            $this->factory->getUserService(),
            $this->factory->getUserRepository(),
            $this->factory->getEventService(),
            $this->factory->getEventRepository(),
            $this->factory->getCategoryRepository(),
            new PasswordHasher(),
            new MockClock(self::NOW),
        ));
    }

    /**
     * Writes a user straight in, bypassing the seeder's naming, along with an
     * event and a row in each table the cleanup reaches into after it.
     */
    private function user(string $login): void
    {
        $id = (int) sprintf('%u', crc32($login)) % 100000;

        $this->pdo->prepare(
            'INSERT INTO webcal_user (cal_login, cal_lastname, cal_firstname, cal_email, cal_is_admin, cal_enabled)
             VALUES (?, ?, ?, ?, ?, ?)',
        )->execute([$login, 'Real', 'Person', "{$login}@example.com", 'N', 'Y']);

        $this->pdo->prepare(
            'INSERT INTO webcal_user_pref (cal_login, cal_setting, cal_value) VALUES (?, ?, ?)',
        )->execute([$login, 'TIMEZONE', 'UTC']);

        $this->pdo->prepare(
            "INSERT INTO webcal_entry (cal_id, cal_create_by, cal_name, cal_date, cal_time, cal_type, cal_access, cal_duration)
             VALUES (?, ?, 'Their meeting', 20260615, 100000, 'E', 'P', 60)",
        )->execute([$id, $login]);

        // The four tables hanging off that event. Cleanup deletes from each of
        // them before the event itself, and nothing checked that it did.
        $this->pdo->prepare('INSERT INTO webcal_entry_categories (cal_id, cat_id, cat_order, cat_owner) VALUES (?, 1, 0, ?)')
            ->execute([$id, $login]);
        $this->pdo->prepare("INSERT INTO webcal_entry_repeats (cal_id, cal_type, cal_frequency) VALUES (?, 'daily', 1)")
            ->execute([$id]);
        $this->pdo->prepare('INSERT INTO webcal_entry_repeats_not (cal_id, cal_date, cal_exdate) VALUES (?, 20260616, 1)')
            ->execute([$id]);
        $this->pdo->prepare("INSERT INTO webcal_entry_user (cal_id, cal_login, cal_status) VALUES (?, ?, 'A')")
            ->execute([$id, $login]);
    }

    /** @return array<string, int> Row counts in every table cleanup touches. */
    private function counts(): array
    {
        $counts = [];
        foreach ([
            'webcal_user',
            'webcal_user_pref',
            'webcal_entry',
            'webcal_entry_categories',
            'webcal_entry_repeats',
            'webcal_entry_repeats_not',
            'webcal_entry_user',
        ] as $table) {
            $counts[$table] = (int) ($this->pdo->query("SELECT COUNT(*) FROM {$table}")?->fetchColumn() ?: 0);
        }

        return $counts;
    }

    /** @return list<string> Logins still in webcal_user. */
    private function logins(): array
    {
        $rows = $this->pdo->query('SELECT cal_login FROM webcal_user ORDER BY cal_login')?->fetchAll(\PDO::FETCH_COLUMN);

        return \is_array($rows) ? array_values(array_map(strval(...), $rows)) : [];
    }

    private function eventCount(): int
    {
        return (int) ($this->pdo->query('SELECT COUNT(*) FROM webcal_entry')?->fetchColumn() ?: 0);
    }

    // ------------------------------------------------------------- seeding

    public function testSeedingWritesUsersEventsAndCategories(): void
    {
        $tester = $this->tester();

        $tester->execute(['--users' => '3', '--events' => '5', '--categories' => '2']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertGreaterThanOrEqual(3, \count($this->logins()));
        self::assertGreaterThan(0, $this->eventCount());
        self::assertStringContainsString('Seeding complete', $tester->getDisplay());
    }

    public function testEverySeededLoginCarriesThePrefixCleanupLooksFor(): void
    {
        // The two halves have to agree: cleanup finds what seeding wrote by
        // its name, and nothing else identifies it.
        $tester = $this->tester();

        $tester->execute(['--users' => '4', '--events' => '2', '--categories' => '1']);

        foreach ($this->logins() as $login) {
            self::assertStringStartsWith('perf_', $login, "{$login} would survive its own cleanup");
        }
    }

    public function testSurnamesCycleOnceTheFirstNamesRunOut(): void
    {
        // There are forty first names and thirty surnames, and the surname is
        // picked by the quotient: user forty is the first to need index 1.
        // Multiply where the code divides and that index becomes 30, one past
        // the end of a thirty-element array -- which only shows up on a run
        // long enough to get there.
        $tester = $this->tester();

        $tester->execute(['--users' => '41', '--events' => '0', '--categories' => '0']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertCount(41, $this->logins());

        // Two different surnames appeared, which is the point of the quotient.
        $surnames = [];
        foreach ($this->logins() as $login) {
            $parts = explode('_', $login);
            $surnames[$parts[2] ?? ''] = true;
        }
        self::assertGreaterThan(1, \count($surnames), 'every user got the same surname');
    }

    // ------------------------------------------------------------- cleanup

    /**
     * `LIKE 'perf_%'` reads the underscore as a wildcard, so it matches perf
     * followed by any character at all. These are ordinary accounts that a
     * --cleanup would have deleted, along with their events and preferences.
     *
     * @return iterable<string, array{string}>
     */
    public static function loginsThatAreNotSeededData(): iterable
    {
        yield 'perfecto' => ['perfecto'];
        yield 'performance' => ['performance'];
        yield 'perfume' => ['perfume'];
        yield 'perfectionist' => ['perfectionist'];
    }

    #[DataProvider('loginsThatAreNotSeededData')]
    public function testCleanupLeavesRealAccountsThatMerelyStartWithPerf(string $login): void
    {
        $this->user($login);
        $this->user('perf_alice_smith_0');

        $tester = $this->tester();
        $tester->execute(['--cleanup' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame([$login], $this->logins(), "{$login} was deleted as if it were seeded data");
        self::assertSame(1, $this->eventCount(), "{$login}'s events went too");
    }

    public function testCleanupStillRemovesWhatTheSeederWrote(): void
    {
        $this->user('perf_alice_smith_0');
        $this->user('perf_bob_jones_1');
        $this->user('alice');

        $tester = $this->tester();
        $tester->execute(['--cleanup' => true]);

        self::assertSame(['alice'], $this->logins());
        self::assertSame(1, $this->eventCount());
    }

    public function testCleanupClearsEveryTableHangingOffTheEvent(): void
    {
        // The four child deletes run before the event itself, and a broken one
        // leaves orphan rows keyed to an event id that no longer exists --
        // silently, because deleting nothing is not an error.
        $this->user('perf_alice_smith_0');

        $tester = $this->tester();
        $tester->execute(['--cleanup' => true]);

        self::assertSame([
            'webcal_user' => 0,
            'webcal_user_pref' => 0,
            'webcal_entry' => 0,
            'webcal_entry_categories' => 0,
            'webcal_entry_repeats' => 0,
            'webcal_entry_repeats_not' => 0,
            'webcal_entry_user' => 0,
        ], $this->counts());
    }

    public function testCleanupLeavesARealAccountsChildRowsAlone(): void
    {
        $this->user('perfecto');

        $tester = $this->tester();
        $tester->execute(['--cleanup' => true]);

        self::assertSame([
            'webcal_user' => 1,
            'webcal_user_pref' => 1,
            'webcal_entry' => 1,
            'webcal_entry_categories' => 1,
            'webcal_entry_repeats' => 1,
            'webcal_entry_repeats_not' => 1,
            'webcal_entry_user' => 1,
        ], $this->counts());
    }

    public function testCleanupSaysHowMuchItRemoved(): void
    {
        $this->user('perf_alice_smith_0');

        $tester = $this->tester();
        $tester->execute(['--cleanup' => true]);

        self::assertStringContainsString('Cleaned up 1 events and 1 users', $tester->getDisplay());
    }

    public function testCleanupOnAnUntouchedDatabaseRemovesNothing(): void
    {
        $this->user('alice');

        $tester = $this->tester();
        $tester->execute(['--cleanup' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(['alice'], $this->logins());
    }

    public function testSeedingThenCleaningUpLeavesWhatWasThereBefore(): void
    {
        $this->user('alice');
        $before = $this->eventCount();

        $tester = $this->tester();
        $tester->execute(['--users' => '3', '--events' => '4', '--categories' => '1']);

        $this->tester()->execute(['--cleanup' => true]);

        self::assertSame(['alice'], $this->logins());
        self::assertSame($before, $this->eventCount());
    }
}
