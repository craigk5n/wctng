<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\CoreServiceFactory;
use App\Service\LegacyDsn;
use App\Service\LegacyImportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'webcalendar:import-legacy',
    description: 'Import data from a legacy WebCalendar database. Auto-detects schema version.',
)]
final class ImportLegacyCommand extends Command
{
    public function __construct(
        private readonly CoreServiceFactory $factory,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('dsn', null, InputOption::VALUE_REQUIRED, 'Legacy database DSN (e.g., mysql://user:pass@host/dbname)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview what would be imported without writing')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dsn = $input->getOption('dsn');
        if (!\is_string($dsn) || $dsn === '') {
            $io->error('Missing required --dsn option. Example: --dsn="mysql://user:pass@host/dbname"');
            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');

        if ($dryRun) {
            $io->warning('DRY RUN mode — no data will be written.');
        }

        // Connect to legacy database
        $io->section('Connecting to legacy database...');

        try {
            $legacyPdo = $this->createLegacyPdo($dsn);
            $io->success('Connected to legacy database.');
        } catch (\Throwable $e) {
            $io->error('Failed to connect: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // Run import
        $io->section('Importing data...');

        $service = new LegacyImportService($this->factory);

        try {
            $stats = $service->import($legacyPdo, $dryRun);
        } catch (\Throwable $e) {
            $io->error('Import failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // Display results
        $io->section('Import Summary');

        $io->table(
            ['Entity', 'Imported', 'Skipped', 'Errors'],
            [
                ['Users', $stats['users']['imported'], $stats['users']['skipped'], $stats['users']['errors']],
                ['Events', $stats['events']['imported'], $stats['events']['skipped'], $stats['events']['errors']],
                ['Categories', $stats['categories']['imported'], $stats['categories']['skipped'], '-'],
                ['Participants', $stats['participants']['imported'], $stats['participants']['skipped'], '-'],
                ['Preferences', $stats['preferences']['imported'], $stats['preferences']['skipped'], '-'],
            ],
        );

        // Schema info
        $columnMap = $service->getColumnMap();
        $io->section('Schema Detection');
        foreach ($columnMap as $table => $columns) {
            $io->text(sprintf('  %s: %d columns', $table, \count($columns)));
        }

        $totalImported = $stats['users']['imported'] + $stats['events']['imported']
            + $stats['categories']['imported'] + $stats['preferences']['imported'];

        if ($dryRun) {
            $io->warning("DRY RUN complete. {$totalImported} items would be imported.");
        } else {
            $io->success("Import complete. {$totalImported} items imported.");
            $io->note('Imported users have random passwords. They must reset their passwords to log in.');
        }

        return Command::SUCCESS;
    }

    private function createLegacyPdo(string $dsn): \PDO
    {
        $parsed = LegacyDsn::parse($dsn);

        $pdo = new \PDO($parsed['dsn'], $parsed['user'], $parsed['password']);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }
}
