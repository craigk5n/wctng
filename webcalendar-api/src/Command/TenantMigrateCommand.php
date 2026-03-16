<?php

declare(strict_types=1);

namespace App\Command;

use App\Tenant\TenantMigrator;
use App\Tenant\TenantRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'tenant:migrate',
    description: 'Run schema migrations on tenant databases.',
)]
final class TenantMigrateCommand extends Command
{
    public function __construct(
        private readonly TenantMigrator $migrator,
        private readonly TenantRepository $tenantRepository,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Run on a single tenant by slug');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->text("Available migrations: {$this->migrator->availableCount()}");

        /** @var string|null $tenantSlug */
        $tenantSlug = $input->getOption('tenant');

        if ($tenantSlug !== null) {
            $tenant = $this->tenantRepository->findBySlug($tenantSlug);
            if ($tenant === null) {
                $io->error("Tenant '{$tenantSlug}' not found.");
                return Command::FAILURE;
            }

            $result = $this->migrator->migrateTenant($tenant);
            $this->printResults($io, [$result]);

            return $result['error'] === null ? Command::SUCCESS : Command::FAILURE;
        }

        $results = $this->migrator->migrateAll();
        $this->printResults($io, $results);

        $errors = array_filter($results, static fn (array $r): bool => $r['error'] !== null);
        if (\count($errors) > 0) {
            $io->warning(\count($errors) . ' tenant(s) had errors.');
            return Command::FAILURE;
        }

        $io->success('All tenants migrated successfully.');

        return Command::SUCCESS;
    }

    /**
     * @param list<array{slug: string, applied: int, skipped: int, error: string|null}> $results
     */
    private function printResults(SymfonyStyle $io, array $results): void
    {
        $rows = [];
        foreach ($results as $r) {
            $rows[] = [
                $r['slug'],
                (string) $r['applied'],
                (string) $r['skipped'],
                $r['error'] ?? 'OK',
            ];
        }

        $io->table(['Tenant', 'Applied', 'Skipped', 'Status'], $rows);
    }
}
