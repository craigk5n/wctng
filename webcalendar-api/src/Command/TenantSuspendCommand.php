<?php

declare(strict_types=1);

namespace App\Command;

use App\Tenant\Tenant;
use App\Tenant\TenantRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'tenant:suspend',
    description: 'Suspend or activate a tenant.',
)]
final class TenantSuspendCommand extends Command
{
    public function __construct(
        private readonly TenantRepository $tenantRepository,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('slug', InputArgument::REQUIRED, 'Tenant slug')
            ->addArgument('action', InputArgument::OPTIONAL, 'Action: suspend or activate', 'suspend');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $slug */
        $slug = $input->getArgument('slug');
        /** @var string $action */
        $action = $input->getArgument('action');

        $existing = $this->tenantRepository->findBySlug($slug);

        if ($existing === null) {
            $io->error("Tenant '{$slug}' not found.");
            return Command::FAILURE;
        }

        $newStatus = $action === 'activate' ? 'active' : 'suspended';

        $updated = new Tenant(
            id: $existing->id(),
            slug: $existing->slug(),
            name: $existing->name(),
            dbHost: $existing->dbHost(),
            dbName: $existing->dbName(),
            dbUser: $existing->dbUser(),
            dbPassword: $existing->dbPassword(),
            plan: $existing->plan(),
            status: $newStatus,
        );

        $this->tenantRepository->save($updated);
        $io->success("Tenant '{$slug}' is now {$newStatus}.");

        return Command::SUCCESS;
    }
}
