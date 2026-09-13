<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\TenantDeleteCommand;
use App\Command\TenantListCommand;
use App\Command\TenantMigrateCommand;
use App\Command\TenantSuspendCommand;
use App\Tenant\Tenant;
use App\Tenant\TenantDatabaseManager;
use App\Tenant\TenantMigrator;
use App\Tenant\TenantPlan;
use App\Tenant\TenantRepository;
use App\Tenant\TenantStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The tenant commands, none of which had executed a line.
 *
 * These are the operator's blunt instruments: one removes a customer, one
 * takes a live one offline. Both take a slug off the command line and act on
 * it, and the only thing standing between a typo and an outage is whatever
 * they check before they write.
 */
final class TenantCommandsTest extends TestCase
{
    private \PDO $pdo;
    private TenantRepository $tenants;

    #[\Override]
    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(TenantRepository::SCHEMA_SQL);

        $this->tenants = new TenantRepository($this->pdo);
    }

    private function seed(string $slug, TenantStatus $status = TenantStatus::Active): int
    {
        return $this->tenants->save(new Tenant(
            id: 0,
            slug: $slug,
            name: ucfirst($slug) . ' Ltd',
            dbHost: '',
            dbName: ':memory:',
            dbUser: '',
            dbPassword: '',
            plan: TenantPlan::Free,
            status: $status,
        ));
    }

    private static function tester(Command $command): CommandTester
    {
        return new CommandTester($command);
    }

    /** @return list<string> Slugs still in the registry. */
    private function slugs(): array
    {
        return array_map(static fn(Tenant $t): string => $t->slug(), $this->tenants->findAll());
    }

    // -------------------------------------------------------- tenant:delete

    public function testDeletingRefusesWithoutForce(): void
    {
        $this->seed('acme');
        $tester = self::tester(new TenantDeleteCommand($this->tenants));

        $tester->execute(['slug' => 'acme']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('--force', $tester->getDisplay());
        self::assertSame(['acme'], $this->slugs(), 'the tenant was removed anyway');
    }

    public function testDeletingASlugThatIsNotThereFails(): void
    {
        $this->seed('acme');
        $tester = self::tester(new TenantDeleteCommand($this->tenants));

        $tester->execute(['slug' => 'typo', '--force' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('not found', $tester->getDisplay());
        self::assertSame(['acme'], $this->slugs(), 'a miss took something else with it');
    }

    public function testDeletingTakesOnlyTheTenantNamed(): void
    {
        $this->seed('acme');
        $this->seed('globex');
        $tester = self::tester(new TenantDeleteCommand($this->tenants));

        $tester->execute(['slug' => 'acme', '--force' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(['globex'], $this->slugs());
    }

    public function testDeletingSaysItOnlyTouchedTheRegistry(): void
    {
        // It runs DELETE FROM tenants and nothing else: the tenant's own
        // database, and everything in it, is still on disk afterwards with
        // nothing pointing at it. The wording is the only thing telling an
        // operator that, so the wording is pinned.
        $this->seed('acme');
        $tester = self::tester(new TenantDeleteCommand($this->tenants));

        $tester->execute(['slug' => 'acme', '--force' => true]);

        self::assertStringContainsString('registry', $tester->getDisplay());
    }

    // ------------------------------------------------------- tenant:suspend

    public function testSuspendingTakesATenantOffline(): void
    {
        $this->seed('acme');
        $tester = self::tester(new TenantSuspendCommand($this->tenants));

        $tester->execute(['slug' => 'acme', 'action' => 'suspend']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('is now suspended', $tester->getDisplay());
        self::assertSame(TenantStatus::Suspended, $this->tenants->findBySlug('acme')?->status());
    }

    public function testActivatingPutsOneBack(): void
    {
        $this->seed('acme', TenantStatus::Suspended);
        $tester = self::tester(new TenantSuspendCommand($this->tenants));

        $tester->execute(['slug' => 'acme', 'action' => 'activate']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('is now active', $tester->getDisplay());
        self::assertSame(TenantStatus::Active, $this->tenants->findBySlug('acme')?->status());
    }

    /** @return iterable<string, array{string}> */
    public static function actionsThatAreNotActions(): iterable
    {
        yield 'a typo for activate' => ['actiavte'];
        yield 'the other obvious spelling' => ['enable'];
        yield 'nonsense' => ['banana'];
        yield 'empty' => [''];
    }

    /**
     * The action was read as `$action === 'activate' ? Active : Suspended`, so
     * everything that was not exactly "activate" meant suspend -- including a
     * misspelling of it. An operator reaching for activate and mistyping it
     * took a live tenant offline and was told "acme is now suspended", which
     * reads like it worked.
     */
    #[DataProvider('actionsThatAreNotActions')]
    public function testAnActionItDoesNotRecogniseIsRefusedRatherThanGuessed(string $action): void
    {
        $this->seed('acme');
        $tester = self::tester(new TenantSuspendCommand($this->tenants));

        $tester->execute(['slug' => 'acme', 'action' => $action]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode(), $action . ' was acted on');
        // Exiting non-zero in silence would leave an operator guessing.
        self::assertStringContainsString('Unknown action', $tester->getDisplay());
        self::assertSame(
            TenantStatus::Active,
            $this->tenants->findBySlug('acme')?->status(),
            $action . ' changed the tenant',
        );
    }

    public function testSuspendingASlugThatIsNotThereFails(): void
    {
        $tester = self::tester(new TenantSuspendCommand($this->tenants));

        $tester->execute(['slug' => 'nobody', 'action' => 'suspend']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('not found', $tester->getDisplay());
    }

    // ---------------------------------------------------------- tenant:list

    public function testListingAnEmptyRegistrySaysSoRatherThanPrintingNothing(): void
    {
        $tester = self::tester(new TenantListCommand($this->tenants));

        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('No tenants', $tester->getDisplay());
        // Instead of the table, not as well as an empty one.
        self::assertStringNotContainsString('Slug', $tester->getDisplay());
    }

    public function testListingNamesEveryTenantAndItsStatus(): void
    {
        $this->seed('acme');
        $this->seed('globex', TenantStatus::Suspended);
        $tester = self::tester(new TenantListCommand($this->tenants));

        $tester->execute([]);
        $display = $tester->getDisplay();

        foreach (['Slug', 'Name', 'Plan', 'Status', 'Created'] as $heading) {
            self::assertStringContainsString($heading, $display, "the {$heading} column went missing");
        }
        self::assertStringContainsString('acme', $display);
        self::assertStringContainsString('globex', $display);
        self::assertStringContainsString('suspended', $display);
        // A tenant that has a created_at shows it. Without this, a Created
        // column that printed the placeholder for every row would pass.
        self::assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $display);
    }

    public function testATenantWithNoCreatedAtShowsAPlaceholderRatherThanNothing(): void
    {
        // Every row the repository writes gets CURRENT_TIMESTAMP, so this only
        // happens to rows that arrived another way -- a hand-run INSERT, or a
        // registry restored from elsewhere. The column still has to line up.
        $this->pdo->exec(
            "INSERT INTO tenants (slug, name, db_host, db_name, db_user, db_password, plan, status, created_at)
             VALUES ('legacy', 'Legacy Ltd', '', ':memory:', '', '', 'free', 'active', NULL)",
        );
        $tester = self::tester(new TenantListCommand($this->tenants));

        $tester->execute([]);

        self::assertStringContainsString('legacy', $tester->getDisplay());
        self::assertStringContainsString("\u{2014}", $tester->getDisplay());
    }

    // ------------------------------------------------------- tenant:migrate

    /** @param array<string, string> $migrations filename (without .sql) => SQL */
    private function migrator(array $migrations = []): TenantMigrator
    {
        $dir = sys_get_temp_dir() . '/wctng-migrations-' . bin2hex(random_bytes(4));
        mkdir($dir);
        foreach ($migrations as $name => $sql) {
            file_put_contents($dir . '/' . $name . '.sql', $sql);
        }

        return new TenantMigrator($this->tenants, new TenantDatabaseManager('secret-for-tests'), $dir);
    }

    public function testMigratingASlugThatIsNotThereFails(): void
    {
        $this->seed('acme');
        $tester = self::tester(new TenantMigrateCommand($this->migrator(), $this->tenants));

        $tester->execute(['--tenant' => 'nobody']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('not found', $tester->getDisplay());
    }

    public function testMigratingAnEmptyRegistryReportsSuccess(): void
    {
        $tester = self::tester(new TenantMigrateCommand($this->migrator(), $this->tenants));

        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('All tenants migrated successfully', $tester->getDisplay());
    }

    public function testMigratingReportsEveryTenantInATable(): void
    {
        $this->seed('acme');
        $this->seed('globex');
        $tester = self::tester(new TenantMigrateCommand($this->migrator(), $this->tenants));

        $tester->execute([]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), 'a clean run reported failure');
        foreach (['Tenant', 'Applied', 'Skipped', 'Status'] as $heading) {
            self::assertStringContainsString($heading, $display, "the {$heading} column went missing");
        }
        self::assertStringContainsString('acme', $display);
        self::assertStringContainsString('globex', $display);
        // A row that went fine says so, rather than leaving the column blank.
        self::assertStringContainsString('OK', $display);
    }

    public function testOneTenantFailingIsReportedRatherThanCountedAsSuccess(): void
    {
        // A migration that cannot run is the case the summary exists for: the
        // command has to come back non-zero and name who failed, not print a
        // table nobody reads and exit 0.
        $this->seed('acme');
        $tester = self::tester(new TenantMigrateCommand(
            $this->migrator(['001_broken' => 'THIS IS NOT SQL;']),
            $this->tenants,
        ));

        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('acme', $tester->getDisplay());
        // How many, not just that there were some.
        self::assertStringContainsString('1 tenant(s) had errors', $tester->getDisplay());
        // And the row says what went wrong, rather than the 'OK' the column
        // falls back to. The prefix is the migrator's own, so this does not
        // depend on how a particular driver words a syntax error.
        self::assertStringContainsString('Migration 001_broken failed', $tester->getDisplay());
    }

    public function testMigratingSaysHowManyMigrationsItKnowsAbout(): void
    {
        $tester = self::tester(new TenantMigrateCommand($this->migrator(), $this->tenants));

        $tester->execute([]);

        self::assertStringContainsString('Available migrations: 0', $tester->getDisplay());
    }
}
