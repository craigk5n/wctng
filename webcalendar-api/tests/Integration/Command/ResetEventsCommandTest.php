<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\ResetEventsCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit-style integration test for DEL-S2.
 *
 * Uses an in-memory SQLite PDO + direct command construction so it does not
 * depend on the KernelTestCase / real MySQL test database.
 */
final class ResetEventsCommandTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Minimal schema for the subset the command touches.
        $this->pdo->exec('CREATE TABLE webcal_entry (cal_id INTEGER PRIMARY KEY, cal_date INT NOT NULL DEFAULT 0)');
        $this->pdo->exec('CREATE TABLE webcal_entry_user (cal_id INT, cal_login TEXT)');
        $this->pdo->exec('CREATE TABLE webcal_entry_categories (cal_id INT, cat_id INT)');
        $this->pdo->exec('CREATE TABLE webcal_entry_ext_user (cal_id INT, cal_fullname TEXT)');
        $this->pdo->exec('CREATE TABLE webcal_entry_log (cal_log_id INTEGER PRIMARY KEY, cal_entry_id INT)');
        $this->pdo->exec('CREATE TABLE webcal_entry_repeats (cal_id INT)');
        $this->pdo->exec('CREATE TABLE webcal_entry_repeats_not (cal_id INT, cal_date INT)');
        $this->pdo->exec('CREATE TABLE webcal_reminders (cal_id INT, cal_date INT)');
        $this->pdo->exec('CREATE TABLE webcal_blob (cal_blob_id INTEGER PRIMARY KEY, cal_id INT)');

        // Seed some rows so we can verify truncation.
        $this->pdo->exec("INSERT INTO webcal_entry (cal_id, cal_date) VALUES (1, 20240101), (2, 20240102)");
        $this->pdo->exec("INSERT INTO webcal_entry_user (cal_id, cal_login) VALUES (1, 'admin'), (2, 'alice')");
        $this->pdo->exec("INSERT INTO webcal_entry_categories (cal_id, cat_id) VALUES (1, 1)");
        $this->pdo->exec("INSERT INTO webcal_reminders (cal_id, cal_date) VALUES (1, 20240101)");
        $this->pdo->exec("INSERT INTO webcal_blob (cal_id) VALUES (1)");
        $this->pdo->exec("INSERT INTO webcal_entry_ext_user (cal_id, cal_fullname) VALUES (1, 'Bob')");
        $this->pdo->exec("INSERT INTO webcal_entry_repeats (cal_id) VALUES (1)");
        $this->pdo->exec("INSERT INTO webcal_entry_repeats_not (cal_id, cal_date) VALUES (1, 20240102)");
    }

    protected function tearDown(): void
    {
        putenv('WCTNG_ALLOW_DESTRUCTIVE_RESET');
    }

    private function tester(string $env = 'dev'): CommandTester
    {
        $command = new ResetEventsCommand($this->pdo, $env);
        return new CommandTester($command);
    }

    private function rows(string $table): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }

    public function testRefusesWithoutForceFlag(): void
    {
        putenv('WCTNG_ALLOW_DESTRUCTIVE_RESET=1');
        $tester = $this->tester('dev');
        $tester->execute([], ['interactive' => false]);

        $this->assertNotSame(0, $tester->getStatusCode());
        $this->assertStringContainsStringIgnoringCase('--force', $tester->getDisplay());
        $this->assertSame(2, $this->rows('webcal_entry'), 'Nothing should have been deleted');
    }

    public function testRefusesWithoutEnvVar(): void
    {
        putenv('WCTNG_ALLOW_DESTRUCTIVE_RESET'); // unset
        $tester = $this->tester('dev');
        $tester->execute(['--force' => true], ['interactive' => false]);

        $this->assertNotSame(0, $tester->getStatusCode());
        $this->assertStringContainsStringIgnoringCase('WCTNG_ALLOW_DESTRUCTIVE_RESET', $tester->getDisplay());
        $this->assertSame(2, $this->rows('webcal_entry'));
    }

    public function testRefusesInProdEnvironment(): void
    {
        putenv('WCTNG_ALLOW_DESTRUCTIVE_RESET=1');
        $tester = $this->tester('prod');
        $tester->execute(['--force' => true], ['interactive' => false]);

        $this->assertNotSame(0, $tester->getStatusCode());
        $this->assertStringContainsStringIgnoringCase('prod', $tester->getDisplay());
        $this->assertSame(2, $this->rows('webcal_entry'));
    }

    public function testAllowsInTestEnvironment(): void
    {
        putenv('WCTNG_ALLOW_DESTRUCTIVE_RESET=1');
        $tester = $this->tester('test');
        $tester->execute(['--force' => true], ['interactive' => false]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame(0, $this->rows('webcal_entry'));
    }

    public function testTruncatesAllEventTables(): void
    {
        putenv('WCTNG_ALLOW_DESTRUCTIVE_RESET=1');
        $tester = $this->tester('dev');
        $tester->execute(['--force' => true], ['interactive' => false]);

        $this->assertSame(0, $tester->getStatusCode());

        $tables = [
            'webcal_entry',
            'webcal_entry_user',
            'webcal_entry_categories',
            'webcal_entry_ext_user',
            'webcal_entry_log',
            'webcal_entry_repeats',
            'webcal_entry_repeats_not',
            'webcal_reminders',
            'webcal_blob',
        ];
        foreach ($tables as $t) {
            $this->assertSame(0, $this->rows($t), "Expected {$t} to be empty");
        }
    }

    public function testOutputListsTables(): void
    {
        putenv('WCTNG_ALLOW_DESTRUCTIVE_RESET=1');
        $tester = $this->tester('dev');
        $tester->execute(['--force' => true], ['interactive' => false]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('webcal_entry', $display);
        $this->assertStringContainsString('webcal_blob', $display);
    }

    public function testHandlesMissingTableGracefully(): void
    {
        // Drop one of the cascade tables to simulate a deployment without it
        // (e.g. a future event_comments table that is added later).
        $this->pdo->exec('DROP TABLE webcal_blob');

        putenv('WCTNG_ALLOW_DESTRUCTIVE_RESET=1');
        $tester = $this->tester('dev');
        $tester->execute(['--force' => true], ['interactive' => false]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame(0, $this->rows('webcal_entry'));
    }

    public function testNonInteractiveSkipsPrompt(): void
    {
        putenv('WCTNG_ALLOW_DESTRUCTIVE_RESET=1');
        $tester = $this->tester('dev');
        $exit = $tester->execute(
            ['--force' => true],
            ['interactive' => false],
        );
        $this->assertSame(0, $exit);
    }
}
