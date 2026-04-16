<?php

declare(strict_types=1);

namespace App\Tests\Integration\Migrations;

use App\Migrations\MigrationDependencyFactoryProvider;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Tools\Console\Command\MigrateCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Integration test for PBP-S12. We can't run the ported 003/004
 * migrations against SQLite because they contain MySQL-only syntax
 * (`CREATE FULLTEXT INDEX`, `ADD COLUMN ... AFTER`), so instead we prove
 * the tooling wiring end-to-end with a pair of SQLite-compatible
 * throwaway migrations. Drives the `migrations:migrate` console command
 * directly — same code path as prod.
 */
final class DoctrineMigrationsIntegrationTest extends TestCase
{
    private static string $migrationsDir;
    private Connection $connection;

    public static function setUpBeforeClass(): void
    {
        self::$migrationsDir = sys_get_temp_dir() . '/wctng_mig_' . bin2hex(random_bytes(4));
        mkdir(self::$migrationsDir);

        file_put_contents(self::$migrationsDir . '/Version20260101000100.php', <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace Testing\Migrations;
            use Doctrine\DBAL\Schema\Schema;
            use Doctrine\Migrations\AbstractMigration;
            final class Version20260101000100 extends AbstractMigration
            {
                public function getDescription(): string { return 'create widgets'; }
                public function up(Schema $schema): void { $this->addSql('CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT)'); }
                public function down(Schema $schema): void { $this->addSql('DROP TABLE widgets'); }
            }
            PHP);
        file_put_contents(self::$migrationsDir . '/Version20260101000200.php', <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace Testing\Migrations;
            use Doctrine\DBAL\Schema\Schema;
            use Doctrine\Migrations\AbstractMigration;
            final class Version20260101000200 extends AbstractMigration
            {
                public function getDescription(): string { return 'widgets.color'; }
                public function up(Schema $schema): void { $this->addSql('ALTER TABLE widgets ADD COLUMN color TEXT DEFAULT NULL'); }
                public function down(Schema $schema): void { /* SQLite pre-3.35 cannot DROP COLUMN; widgets table will be dropped by 100's down anyway */ }
            }
            PHP);

        require_once self::$migrationsDir . '/Version20260101000100.php';
        require_once self::$migrationsDir . '/Version20260101000200.php';
    }

    public static function tearDownAfterClass(): void
    {
        foreach (glob(self::$migrationsDir . '/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir(self::$migrationsDir);
    }

    #[\Override]
    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }

    private function factory(): DependencyFactory
    {
        $config = new ConfigurationArray([
            'migrations_paths' => ['Testing\\Migrations' => self::$migrationsDir],
            'table_storage' => ['table_name' => MigrationDependencyFactoryProvider::VERSION_TABLE],
        ]);

        return DependencyFactory::fromConnection($config, new ExistingConnection($this->connection));
    }

    private function runMigrate(DependencyFactory $factory, string $version): int
    {
        $command = new MigrateCommand($factory);
        $input = new ArrayInput([
            'version' => $version,
            '--allow-no-migration' => true,
            '--no-all-or-nothing' => true,
        ]);
        $input->setInteractive(false);

        return $command->run($input, new BufferedOutput());
    }

    public function testMigrateUpAndDownViaConsoleCommand(): void
    {
        $factory = $this->factory();

        $upExit = $this->runMigrate($factory, 'latest');
        self::assertSame(0, $upExit);

        $widgetsExists = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='widgets'",
        );
        self::assertSame(1, $widgetsExists, 'widgets created by up');

        // Version tracking uses our named table
        $executed = $this->connection->fetchAllAssociative(
            'SELECT version FROM ' . MigrationDependencyFactoryProvider::VERSION_TABLE . ' ORDER BY version',
        );
        self::assertCount(2, $executed);

        // Each DependencyFactory memoizes its connection-state between migrate
        // calls; use a fresh one for the rollback so the planner recomputes
        // from the tracked state. 'prev' rolls back exactly one executed
        // migration (the second), which exercises the down() path.
        $rollbackFactory = $this->factory();
        $downExit = $this->runMigrate($rollbackFactory, 'prev');
        self::assertSame(0, $downExit);

        // After rolling back by one, the first migration is still applied so
        // widgets should still exist; only the 200 version is removed from the
        // tracking table.
        $widgetsStillThere = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='widgets'",
        );
        self::assertSame(1, $widgetsStillThere, 'widgets still present after rolling back one step');

        $remaining = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ' . MigrationDependencyFactoryProvider::VERSION_TABLE,
        );
        self::assertSame(1, $remaining, 'only the first migration remains recorded');
    }

    public function testVersionTableNameMatchesProviderConstant(): void
    {
        $factory = $this->factory();

        $this->runMigrate($factory, 'latest');

        $table = (string) $this->connection->fetchOne(
            "SELECT name FROM sqlite_master WHERE type='table' AND name = ?",
            [MigrationDependencyFactoryProvider::VERSION_TABLE],
        );

        self::assertSame(MigrationDependencyFactoryProvider::VERSION_TABLE, $table);
    }

    public function testMigrateIsIdempotentWhenAlreadyAtLatest(): void
    {
        $factory = $this->factory();

        $first = $this->runMigrate($factory, 'latest');
        self::assertSame(0, $first);

        // A second migrate to latest should be a clean no-op — no rerun of either
        // migration (which would fail with 'table widgets already exists' if it did).
        $second = $this->runMigrate($this->factory(), 'latest');
        self::assertSame(0, $second);
    }
}
