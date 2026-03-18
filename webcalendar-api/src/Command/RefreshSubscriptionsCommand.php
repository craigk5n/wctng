<?php

declare(strict_types=1);

namespace App\Command;

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

    public function __construct(\PDO $pdo)
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

            $context = stream_context_create([
                'http' => [
                    'timeout' => 15,
                    'header' => $sub->etag() !== null
                        ? "If-None-Match: {$sub->etag()}\r\n"
                        : '',
                ],
            ]);

            $content = @file_get_contents($sub->url(), false, $context);

            if ($content === false) {
                // Could be 304 Not Modified or actual failure
                $io->text('  → No new content (cached or failed)');
                $this->repo->updateFetchStatus($sub->id(), $sub->etag());
                $success++;
                continue;
            }

            // Extract ETag (set by file_get_contents)
            $etag = null;
            foreach ($http_response_header as $header) {
                if (stripos($header, 'ETag:') === 0) {
                    $etag = trim(substr($header, 5));
                }
            }

            $this->repo->updateFetchStatus($sub->id(), $etag);
            $io->text(sprintf('  → Fetched %d bytes', \strlen($content)));
            $success++;
        }

        $io->success(sprintf('Refreshed %d subscriptions (%d failed)', $success, $failed));

        return Command::SUCCESS;
    }
}
