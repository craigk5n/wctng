<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\RefreshSubscriptionsCommand;
use App\Subscription\SubscriptionRepository;
use App\Tests\Unit\Subscription\RecordingIcsFetcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The background half of the subscription feature.
 *
 * Nothing executed it. It is the second place a stored subscription URL turns
 * into an outbound request -- on a schedule, without anyone asking -- so it
 * gets the same checks the on-demand route does, and a URL that fails them has
 * to be skipped rather than take the whole pass down.
 */
final class RefreshSubscriptionsCommandTest extends TestCase
{
    private \PDO $pdo;
    private SubscriptionRepository $repo;

    #[\Override]
    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->repo = new SubscriptionRepository($this->pdo);
    }

    private function refresh(RecordingIcsFetcher $fetcher): CommandTester
    {
        $tester = new CommandTester(new RefreshSubscriptionsCommand($this->pdo, $fetcher));
        $tester->execute([]);

        return $tester;
    }

    public function testAFetchedFeedStoresTheValidatorItCameBackWith(): void
    {
        $sub = $this->repo->create('alice', 'https://example.com/cal.ics', 'Alice', '#aaaaaa');
        $fetcher = new RecordingIcsFetcher(['body' => 'BEGIN:VCALENDAR', 'etag' => '"v2"']);

        $tester = $this->refresh($fetcher);

        self::assertSame([['url' => 'https://example.com/cal.ics', 'etag' => null]], $fetcher->calls);
        $stored = $this->repo->findById($sub->id());
        self::assertNotNull($stored);
        self::assertSame('"v2"', $stored->etag());
        self::assertNotNull($stored->lastFetched());
        self::assertStringContainsString('Fetched 15 bytes', $tester->getDisplay());
    }

    public function testAnUnchangedFeedKeepsTheValidatorItAlreadyHad(): void
    {
        $sub = $this->repo->create('alice', 'https://example.com/cal.ics', 'Alice', '#aaaaaa');
        $this->repo->updateFetchStatus($sub->id(), '"v1"');

        $this->refresh(new RecordingIcsFetcher());

        self::assertSame('"v1"', $this->repo->findById($sub->id())?->etag());
    }

    /**
     * A row stored before the outbound checks existed is still in the table,
     * and this job would otherwise keep requesting it on every pass forever.
     */
    public function testAUrlThatFailsTheChecksIsSkippedRatherThanFetched(): void
    {
        $sub = $this->repo->create('alice', 'file:///etc/passwd', 'Legacy', '#aaaaaa');
        $fetcher = new RecordingIcsFetcher(
            rejection: new \InvalidArgumentException('URL scheme "file" is not allowed; use http or https.'),
        );

        $tester = $this->refresh($fetcher);

        self::assertStringContainsString('Skipped', $tester->getDisplay());
        self::assertStringContainsString('(1 failed)', $tester->getDisplay());
        self::assertNull($this->repo->findById($sub->id())?->lastFetched());
    }

    public function testOneUnusableSubscriptionDoesNotStopTheRest(): void
    {
        $this->repo->create('alice', 'file:///etc/passwd', 'Legacy', '#aaaaaa');
        $this->repo->create('bob', 'https://example.com/b.ics', 'Bob', '#bbbbbb');
        $fetcher = new RecordingIcsFetcher(
            rejection: new \InvalidArgumentException('URL scheme "file" is not allowed; use http or https.'),
        );

        $tester = $this->refresh($fetcher);

        self::assertCount(2, $fetcher->calls, 'the pass stopped at the first unusable row');
        self::assertSame(0, $tester->getStatusCode());
    }

    public function testEveryDueSubscriptionIsVisited(): void
    {
        $this->repo->create('alice', 'https://a.example/1.ics', 'Alpha', '#111');
        $this->repo->create('bob', 'https://b.example/2.ics', 'Beta', '#222');
        $this->repo->create('carol', 'https://c.example/3.ics', 'Gamma', '#333');
        $fetcher = new RecordingIcsFetcher(['body' => 'BEGIN:VCALENDAR', 'etag' => null]);

        $tester = $this->refresh($fetcher);

        self::assertCount(3, $fetcher->calls);
        // The per-row line is the only record of which calendar the job was
        // working on when something went wrong.
        self::assertStringContainsString('Refreshing: Beta (https://b.example/2.ics)', $tester->getDisplay());
        self::assertStringContainsString('Found 3 subscriptions due for refresh', $tester->getDisplay());
        self::assertStringContainsString('Refreshed 3 subscriptions (0 failed)', $tester->getDisplay());
    }

    public function testASubscriptionInsideItsIntervalIsLeftAlone(): void
    {
        $sub = $this->repo->create('alice', 'https://example.com/cal.ics', 'Alice', '#aaaaaa', 3600);
        $this->repo->updateFetchStatus($sub->id(), '"v1"');
        $fetcher = new RecordingIcsFetcher(['body' => 'BEGIN:VCALENDAR', 'etag' => '"v2"']);

        $this->refresh($fetcher);

        self::assertSame([], $fetcher->calls);
        self::assertSame('"v1"', $this->repo->findById($sub->id())?->etag());
    }
}
