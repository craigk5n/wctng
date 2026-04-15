<?php

declare(strict_types=1);

namespace App\Command;

use App\Tenant\TenantRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'tenant:list',
    description: 'List all tenants with their status.',
)]
final class TenantListCommand extends Command
{
    public function __construct(
        private readonly TenantRepository $tenantRepository,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tenants = $this->tenantRepository->findAll();

        if ($tenants === []) {
            $io->info('No tenants found.');
            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($tenants as $tenant) {
            $rows[] = [
                $tenant->slug(),
                $tenant->name(),
                $tenant->plan()->value,
                $tenant->status()->value,
                $tenant->createdAt()?->format('Y-m-d H:i') ?? '—',
            ];
        }

        $io->table(['Slug', 'Name', 'Plan', 'Status', 'Created'], $rows);

        return Command::SUCCESS;
    }
}
