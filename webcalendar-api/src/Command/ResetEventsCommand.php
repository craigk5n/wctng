<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Destructive dev-only reset: truncates every event-related table so a
 * developer can re-seed from scratch.
 *
 * Triple-gated:
 *   1. APP_ENV must be dev or test (never prod / staging)
 *   2. --force option must be passed
 *   3. WCTNG_ALLOW_DESTRUCTIVE_RESET=1 must be set in the environment
 *
 * See STATUS.md story DEL-S2.
 */
#[AsCommand(
    name: 'webcalendar:dev:reset-events',
    description: 'Truncate all event tables (dev/test only — destroys data).',
)]
final class ResetEventsCommand extends Command
{
    /**
     * Order matters only for FK-constrained databases; SQLite/MySQL with
     * FKs disabled tolerate any order. Parents listed last.
     *
     * @var list<string>
     */
    private const EVENT_TABLES = [
        'webcal_entry_user',
        'webcal_entry_categories',
        'webcal_entry_ext_user',
        'webcal_entry_log',
        'webcal_entry_repeats',
        'webcal_entry_repeats_not',
        'webcal_reminders',
        'webcal_blob',
        'webcal_entry',
    ];

    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $appEnv,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Required to actually run — refuses without it.');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Guard 1: environment.
        if (!\in_array($this->appEnv, ['dev', 'test'], true)) {
            $io->error("Refusing to run in '{$this->appEnv}' environment. This command is only allowed in dev or test.");
            return Command::FAILURE;
        }

        // Guard 2: --force option.
        if (!$input->getOption('force')) {
            $io->error('--force is required. This command truncates every event table.');
            return Command::FAILURE;
        }

        // Guard 3: opt-in env var.
        $allow = getenv('WCTNG_ALLOW_DESTRUCTIVE_RESET');
        if ($allow !== '1') {
            $io->error('WCTNG_ALLOW_DESTRUCTIVE_RESET=1 must be set in the environment to confirm intent.');
            return Command::FAILURE;
        }

        $dbName = $this->detectDatabaseName();
        $io->warning("About to truncate event tables in database: {$dbName}");
        $io->listing(self::EVENT_TABLES);

        if ($input->isInteractive() && !$io->confirm('Proceed?', false)) {
            $io->note('Aborted.');
            return Command::SUCCESS;
        }

        $summary = [];
        foreach (self::EVENT_TABLES as $table) {
            try {
                $stmt = $this->pdo->query("SELECT COUNT(*) FROM {$table}");
                $before = $stmt !== false ? (int) $stmt->fetchColumn() : 0;
                $this->pdo->exec("DELETE FROM {$table}");
                $summary[] = [$table, $before];
            } catch (\PDOException $e) {
                if ($this->isMissingTableError($e)) {
                    $summary[] = [$table, 'skipped (missing)'];
                    continue;
                }
                $io->error("Failed to truncate {$table}: {$e->getMessage()}");
                return Command::FAILURE;
            }
        }

        $io->table(['Table', 'Rows deleted'], $summary);
        $io->success('Event tables reset.');

        return Command::SUCCESS;
    }

    private function detectDatabaseName(): string
    {
        try {
            $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                return 'sqlite';
            }
            $result = $this->pdo->query('SELECT DATABASE()');
            if ($result !== false) {
                $name = $result->fetchColumn();
                if (\is_string($name) && $name !== '') {
                    return $name;
                }
            }
        } catch (\PDOException) {
            // ignore
        }
        return '(unknown)';
    }

    private function isMissingTableError(\PDOException $e): bool
    {
        $msg = $e->getMessage();
        return str_contains($msg, 'no such table')
            || str_contains($msg, "doesn't exist")
            || str_contains($msg, 'does not exist');
    }
}
