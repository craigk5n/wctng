<?php

declare(strict_types=1);

namespace App\Command;

use App\Subscription\IcsFetcher;
use App\Subscription\SubscriptionRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'webcalendar:refresh-subscriptions',
    description: 'Refresh ICS calendar subscriptions that are due for update',
)]
final class RefreshSubscriptionsCommand extends Command
{
    private readonly SubscriptionRepository $repo;

    public function __construct(\PDO $pdo, private readonly IcsFetcher $fetcher)
    {
        parent::__construct();
        $this->repo = new SubscriptionRepository($pdo);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $due = $this->repo->findDueForRefresh();
        $io->info(sprintf('Found %d subscriptions due for refresh', \count($due)));

        $success = 0;
        $failed = 0;

        foreach ($due as $sub) {
            $io->text(sprintf('Refreshing: %s (%s)', $sub->name(), $sub->url()));

            try {
                $fetched = $this->fetcher->fetch($sub->url(), $sub->etag());
            } catch (\InvalidArgumentException $e) {
                // A URL stored before the outbound checks existed, or a host
                // that now answers with an internal address.
                $io->text(sprintf('  → Skipped: %s', $e->getMessage()));
                $failed++;
                continue;
            }

            if ($fetched === null) {
                // Could be 304 Not Modified or actual failure
                $io->text('  → No new content (cached or failed)');
                $this->repo->updateFetchStatus($sub->id(), $sub->etag());
                $success++;
                continue;
            }

            $this->repo->updateFetchStatus($sub->id(), $fetched['etag']);
            $io->text(sprintf('  → Fetched %d bytes', \strlen($fetched['body'])));
            $success++;
        }

        $io->success(sprintf('Refreshed %d subscriptions (%d failed)', $success, $failed));

        return Command::SUCCESS;
    }
}
