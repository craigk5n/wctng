<?php

declare(strict_types=1);

namespace App\Command;

use App\Tenant\TenantProvisioner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'tenant:create',
    description: 'Provision a new tenant with database, schema, and admin user.',
)]
final class TenantCreateCommand extends Command
{
    public function __construct(
        private readonly TenantProvisioner $provisioner,
        private readonly string $baseDomain,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('slug', InputArgument::REQUIRED, 'Tenant slug (lowercase, 3-50 chars)')
            ->addArgument('name', InputArgument::REQUIRED, 'Tenant display name')
            ->addOption('admin-email', null, InputOption::VALUE_REQUIRED, 'Admin user email', 'admin@example.com')
            ->addOption('plan', null, InputOption::VALUE_REQUIRED, 'Tenant plan', 'free');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $slug */
        $slug = $input->getArgument('slug');
        /** @var string $name */
        $name = $input->getArgument('name');
        /** @var string $email */
        $email = $input->getOption('admin-email');
        /** @var string $plan */
        $plan = $input->getOption('plan');

        $io->text("Provisioning tenant: {$slug}");

        $result = $this->provisioner->provision($slug, $name, $email, $plan);

        if (!$result->success) {
            $io->error($result->error);
            return Command::FAILURE;
        }

        $io->success('Tenant provisioned successfully!');
        $io->table(['Field', 'Value'], [
            ['Slug', $result->slug],
            ['URL', "https://{$result->slug}.{$this->baseDomain}"],
            ['Admin Email', $result->adminEmail],
            ['Admin Password', $result->adminPassword],
        ]);

        return Command::SUCCESS;
    }
}
