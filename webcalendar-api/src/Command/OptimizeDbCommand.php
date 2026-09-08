<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\TenantAwarePdoProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'webcalendar:optimize-db',
    description: 'Add performance indexes to the database.',
)]
final class OptimizeDbCommand extends Command
{
    public function __construct(
        private readonly TenantAwarePdoProvider $pdoProvider,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $pdo = $this->pdoProvider->get();

        $indexes = [
            'CREATE INDEX IF NOT EXISTS idx_entry_date ON webcal_entry (cal_date)',
            'CREATE INDEX IF NOT EXISTS idx_entry_create_by ON webcal_entry (cal_create_by)',
            'CREATE INDEX IF NOT EXISTS idx_entry_type ON webcal_entry (cal_type)',
            'CREATE INDEX IF NOT EXISTS idx_entry_date_user ON webcal_entry (cal_create_by, cal_date)',
            'CREATE INDEX IF NOT EXISTS idx_entry_user_cal ON webcal_entry_user (cal_id, cal_login)',
            'CREATE INDEX IF NOT EXISTS idx_entry_categories_cal ON webcal_entry_categories (cal_id)',
            'CREATE INDEX IF NOT EXISTS idx_entry_categories_cat ON webcal_entry_categories (cat_id)',
            'CREATE INDEX IF NOT EXISTS idx_user_layers_login ON webcal_user_layers (cal_login)',
        ];

        $created = 0;
        foreach ($indexes as $sql) {
            try {
                $pdo->exec($sql);
                $created++;
            } catch (\PDOException $e) {
                $io->warning("Failed: {$sql} — {$e->getMessage()}");
            }
        }

        $io->success("Created/verified {$created} indexes.");

        return Command::SUCCESS;
    }
}
