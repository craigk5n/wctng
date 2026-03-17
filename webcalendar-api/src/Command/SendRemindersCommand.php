<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ReminderService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'webcalendar:send-reminders',
    description: 'Send reminder emails for upcoming events. Run via cron every minute.',
)]
final class SendRemindersCommand extends Command
{
    public function __construct(
        private readonly ReminderService $reminderService,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $count = $this->reminderService->sendReminders();

        if ($count > 0) {
            $io->success("Sent {$count} reminder(s).");
        } else {
            $io->text('No reminders to send.');
        }

        return Command::SUCCESS;
    }
}
