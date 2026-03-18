<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\DailyAgendaService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'webcalendar:send-daily-agenda',
    description: 'Send daily agenda emails to opted-in users. Run via cron every hour.',
)]
final class SendDailyAgendaCommand extends Command
{
    public function __construct(
        private readonly DailyAgendaService $agendaService,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $count = $this->agendaService->sendAgendas();

        if ($count > 0) {
            $io->success("Sent {$count} daily agenda(s).");
        } else {
            $io->text('No agendas to send.');
        }

        return Command::SUCCESS;
    }
}
