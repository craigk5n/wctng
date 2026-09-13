<?php

declare(strict_types=1);

namespace App\Command;

use App\Tenant\Tenant;
use App\Tenant\TenantRepository;
use App\Tenant\TenantStatus;
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

        // Read as `$action === 'activate' ? Active : Suspended`, everything
        // that was not exactly "activate" meant suspend -- a misspelling of it
        // included. Reaching for activate and mistyping it took a live tenant
        // offline, and said "is now suspended" as though that had been asked
        // for. An argument naming a verb has to be a verb it knows.
        $newStatus = match ($action) {
            'activate' => TenantStatus::Active,
            'suspend' => TenantStatus::Suspended,
            default => null,
        };

        if ($newStatus === null) {
            $io->error("Unknown action '{$action}'. Use 'suspend' or 'activate'.");
            return Command::FAILURE;
        }

        $existing = $this->tenantRepository->findBySlug($slug);

        if ($existing === null) {
            $io->error("Tenant '{$slug}' not found.");
            return Command::FAILURE;
        }

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
        $io->success("Tenant '{$slug}' is now {$newStatus->value}.");

        return Command::SUCCESS;
    }
}
