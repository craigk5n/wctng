<?php

declare(strict_types=1);

namespace App\Command;

use App\Tenant\TenantRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'tenant:delete',
    description: 'Delete a tenant and its registry entry.',
)]
final class TenantDeleteCommand extends Command
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
            ->addOption('force', null, InputOption::VALUE_NONE, 'Required to confirm deletion');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $slug */
        $slug = $input->getArgument('slug');

        if (!$input->getOption('force')) {
            $io->error("Deleting a tenant is destructive. Use --force to confirm.");
            return Command::FAILURE;
        }

        $existing = $this->tenantRepository->findBySlug($slug);

        if ($existing === null) {
            $io->error("Tenant '{$slug}' not found.");
            return Command::FAILURE;
        }

        $this->tenantRepository->delete($existing->id());
        $io->success("Tenant '{$slug}' deleted from registry.");

        return Command::SUCCESS;
    }
}
