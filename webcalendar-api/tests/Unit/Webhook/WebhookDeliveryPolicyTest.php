<?php

declare(strict_types=1);

namespace App\Tests\Unit\Webhook;

use App\Webhook\WebhookDispatcher;
use App\Webhook\WebhookRepository;
use App\Webhook\WebhookSubscription;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\MockClock;

/**
 * Covers what the dispatcher decides around a delivery, as opposed to whether
 * curl itself works: retry count, attempt numbering, log mapping, pruning, and
 * that a non-matching subscription does not stop the ones behind it.
 *
 * Status codes come from FakeWebhookTransport, so both branches of the
 * success test are reachable -- which they were not while curl was welded into
 * the dispatcher.
 */
final class WebhookDeliveryPolicyTest extends TestCase
{
    private \PDO $pdo;
    private WebhookRepository $repo;
    private RecordingLogger $logger;
    private FakeWebhookTransport $transport;
    private WebhookDispatcher $dispatcher;

    #[\Override]
    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->repo = new WebhookRepository($this->pdo);
        $this->logger = new RecordingLogger();
        $this->transport = new FakeWebhookTransport([500, 500, 500]);
        $this->dispatcher = $this->dispatcherWith($this->transport);
    }

    private function dispatcherWith(FakeWebhookTransport $transport): WebhookDispatcher
    {
        return new WebhookDispatcher(
            $this->repo,
            $this->pdo,
            $transport,
            $this->logger,
            null,
            retryDelays: [0, 0, 0],
        );
    }

    private function subscribe(string $events = '*', string $host = 'hooks.example.com'): int
    {
        return $this->repo->save(
            new WebhookSubscription(0, 'https://' . $host . '/hook', $events, 'secret', true)
        );
    }

    public function testDispatchSkipsUnsubscribedWebhookButKeepsGoing(): void
    {
        // `continue` rather than `break`: a subscription that does not want
        // this event must not stop the ones after it from being delivered.
        $this->repo->save(new WebhookSubscription(0, 'https://other.example.com/a', 'event.deleted', 's', true));
        $wanted = $this->subscribe('event.created');

        $this->dispatcher->dispatch('event.created', ['id' => 1]);

        self::assertNotEmpty(
            $this->dispatcher->getDeliveryLog($wanted),
            'the second subscription still receives the event',
        );
    }

    public function testRetriesExactlyMaxRetriesTimesAndNumbersAttemptsFromOne(): void
    {
        $id = $this->subscribe();

        $this->dispatcher->dispatch('event.created', ['id' => 1]);

        $log = $this->dispatcher->getDeliveryLog($id);
        self::assertCount(3, $log, 'MAX_RETRIES attempts, not one more or fewer');
        // The attempt number is carried in response_body. Sorted, because with
        // the backoff at zero all three rows share a delivered_at and the
        // ORDER BY cannot break the tie. `$attempt + 1` -- a `- 1` would
        // number these 0, -1, -2.
        $responses = array_map(static fn(array $r): string => $r['response'], $log);
        sort($responses);
        self::assertSame(['attempt 1', 'attempt 2', 'attempt 3'], $responses);
    }

    public function testInjectedLoggerIsUsedAndRecordsEachFailedAttempt(): void
    {
        // `new NullLogger() ?? $logger` would silently discard the injected one.
        $id = $this->subscribe();

        $this->dispatcher->dispatch('event.created', ['id' => 1]);

        $failures = array_values(array_filter(
            $this->logger->records,
            static fn(array $r): bool => $r['message'] === 'Webhook delivery failed',
        ));

        self::assertCount(3, $failures);
        self::assertSame($id, $failures[0]['context']['webhook_id']);
        self::assertSame(1, $failures[0]['context']['attempt']);
        self::assertSame(3, $failures[2]['context']['attempt']);
        self::assertSame(500, $failures[0]['context']['status']);
    }

    public function testDeliveryLogReadsBackTheStoredValues(): void
    {
        $id = $this->subscribe();

        $this->dispatcher->dispatch('event.created', ['id' => 1]);

        $row = $this->dispatcher->getDeliveryLog($id)[0];
        // Each of these is a ternary whose two branches were interchangeable
        // without any test noticing.
        self::assertSame($id, $row['webhook_id']);
        self::assertSame(500, $row['status_code']);
        self::assertMatchesRegularExpression('/^attempt [123]$/', $row['response']);
        self::assertNotSame('', $row['delivered_at']);
    }

    public function testDeliveryLogCreatesItsTableOnFirstRead(): void
    {
        // getDeliveryLog() calls ensureLogTable() before querying; without it
        // the very first read raises "no such table" instead of returning [].
        self::assertSame([], $this->dispatcher->getDeliveryLog(4242));
    }

    public function testDeliveryLogIsPrunedToOneHundredPerWebhook(): void
    {
        $id = $this->subscribe();

        // 105 rows, then a dispatch so the prune that accompanies each insert
        // runs against a table that is already over the limit.
        $this->dispatcher->getDeliveryLog($id); // creates the table
        for ($i = 0; $i < 105; $i++) {
            $this->pdo->prepare(
                'INSERT INTO webhook_delivery_log (webhook_id, status_code, response_body, delivered_at)
                 VALUES (:wid, 500, :body, :at)',
            )->execute([
                'wid' => $id,
                'body' => 'seed ' . $i,
                'at' => sprintf('2026-01-01 %02d:%02d:00', intdiv($i, 60), $i % 60),
            ]);
        }

        $this->dispatcher->dispatch('event.created', ['id' => 1]);

        $count = (int) $this->pdo
            ->query('SELECT COUNT(*) FROM webhook_delivery_log WHERE webhook_id = ' . $id)
            ->fetchColumn();

        self::assertLessThanOrEqual(100, $count, 'the prune keeps the log bounded');
        self::assertCount(
            100,
            $this->dispatcher->getDeliveryLog($id),
            'and the default page returns all of what it keeps',
        );
    }

    public function testSuccessStopsAfterOneAttempt(): void
    {
        $transport = new FakeWebhookTransport([200]);
        $id = $this->subscribe();

        $this->dispatcherWith($transport)->dispatch('event.created', ['id' => 1]);

        self::assertSame(1, $transport->calls(), 'a 2xx must not be retried');
        self::assertCount(1, $this->dispatcher->getDeliveryLog($id));
    }

    public function testRetryingStopsAsSoonAsItSucceeds(): void
    {
        $transport = new FakeWebhookTransport([500, 200, 200]);
        $this->subscribe();

        $this->dispatcherWith($transport)->dispatch('event.created', ['id' => 1]);

        self::assertSame(2, $transport->calls());
    }

    /** @return list<array{int, bool}> */
    public static function statusCodes(): array
    {
        return [
            [199, false], // just below the success range
            [200, true],  // first success code
            [204, true],
            [299, true],  // last success code
            [300, false], // just above
            [404, false],
            [500, false],
        ];
    }

    #[DataProvider('statusCodes')]
    public function testOnlyTheTwoHundredRangeCountsAsDelivered(int $status, bool $delivered): void
    {
        // Both bounds matter: >= 200 and < 300 were each mutable in four ways
        // with nothing able to tell the difference.
        $transport = new FakeWebhookTransport([$status, $status, $status]);
        $this->subscribe();

        $this->dispatcherWith($transport)->dispatch('event.created', ['id' => 1]);

        self::assertSame($delivered ? 1 : 3, $transport->calls());
    }

    public function testSuccessIsLoggedAsDelivered(): void
    {
        $transport = new FakeWebhookTransport([201]);
        $this->subscribe();

        $this->dispatcherWith($transport)->dispatch('event.created', ['id' => 1]);

        $messages = array_column($this->logger->records, 'message');
        self::assertContains('Webhook delivered', $messages);
        self::assertNotContains('Webhook delivery failed', $messages);
    }

    public function testBlockedTargetIsLoggedAsBlockedRatherThanFailed(): void
    {
        // An SSRF-rejected target and an unreachable one both yield status 0;
        // only the log distinguishes them.
        $url = 'https://hooks.example.com/hook';
        $transport = new FakeWebhookTransport([200], blockedUrl: $url);
        $this->subscribe();

        $this->dispatcherWith($transport)->dispatch('event.created', ['id' => 1]);

        $blocked = array_values(array_filter(
            $this->logger->records,
            static fn(array $r): bool => $r['message'] === 'Webhook delivery blocked',
        ));

        self::assertNotSame([], $blocked);
        self::assertSame($url, $blocked[0]['context']['url']);
        self::assertSame(0, $transport->calls(), 'a blocked target is never sent');
    }

    public function testPayloadIsSignedWithTheSubscriptionSecret(): void
    {
        $transport = new FakeWebhookTransport([200]);
        $this->subscribe();

        $this->dispatcherWith($transport)->dispatch('event.created', ['id' => 1]);

        self::assertSame(
            hash_hmac('sha256', $transport->sent[0]['payload'], 'secret'),
            $transport->sent[0]['signature'],
        );
    }



    // ------------------------------------------------------- the backoff

    /**
     * @param list<int> $retryDelays
     */
    private function dispatcherSleepingWith(
        FakeWebhookTransport $transport,
        FakeSleeper $sleeper,
        array $retryDelays,
    ): WebhookDispatcher {
        return new WebhookDispatcher(
            $this->repo,
            $this->pdo,
            $transport,
            $this->logger,
            null,
            retryDelays: $retryDelays,
            sleeper: $sleeper,
        );
    }

    public function testItWaitsTheConfiguredIntervalBetweenAttempts(): void
    {
        // Every other case here configures the backoff to zero, which is what
        // makes the retry loop affordable to test -- and also what made the
        // schedule invisible: with the interval at zero, waiting the wrong
        // amount, waiting the wrong number of times, and not waiting at all
        // are indistinguishable.
        $sleeper = new FakeSleeper();
        $this->subscribe();

        $this->dispatcherSleepingWith(new FakeWebhookTransport([500, 500, 500]), $sleeper, [1, 5, 30])
            ->dispatch('event.created', ['id' => 1]);

        self::assertSame([1, 5], $sleeper->slept, 'it waits between attempts, not after the last one');
    }

    public function testItDoesNotWaitAfterASuccessfulAttempt(): void
    {
        $sleeper = new FakeSleeper();
        $this->subscribe();

        $this->dispatcherSleepingWith(new FakeWebhookTransport([200]), $sleeper, [1, 5, 30])
            ->dispatch('event.created', ['id' => 1]);

        self::assertSame([], $sleeper->slept);
    }

    public function testItWaitsOnceWhenTheSecondAttemptSucceeds(): void
    {
        $sleeper = new FakeSleeper();
        $this->subscribe();

        $this->dispatcherSleepingWith(new FakeWebhookTransport([500, 200]), $sleeper, [1, 5, 30])
            ->dispatch('event.created', ['id' => 1]);

        self::assertSame([1], $sleeper->slept);
    }

    public function testABackoffShorterThanTheRetryCountFallsBackToNoWait(): void
    {
        // retryDelays is injectable, so it can be shorter than MAX_RETRIES.
        // The missing entry has to read as "do not wait" rather than as an
        // undefined index.
        $sleeper = new FakeSleeper();
        $this->subscribe();

        $this->dispatcherSleepingWith(new FakeWebhookTransport([500, 500, 500]), $sleeper, [7])
            ->dispatch('event.created', ['id' => 1]);

        self::assertSame([7, 0], $sleeper->slept);
    }

    // -------------------------------------------------------- the payload

    public function testThePayloadCarriesTheEventItsTimeAndItsData(): void
    {
        // Nothing injected a clock, so the timestamp was whatever the wall
        // clock said and no test could assert it -- which left the whole
        // envelope unpinned.
        $transport = new FakeWebhookTransport([200]);
        $this->subscribe();

        $dispatcher = new WebhookDispatcher(
            $this->repo,
            $this->pdo,
            $transport,
            $this->logger,
            new MockClock('2026-06-15T12:00:00+00:00'),
            retryDelays: [0, 0, 0],
        );
        $dispatcher->dispatch('event.created', ['id' => 7, 'title' => 'Standup']);

        self::assertSame(
            [
                'event' => 'event.created',
                'timestamp' => '2026-06-15T12:00:00+00:00',
                'data' => ['id' => 7, 'title' => 'Standup'],
            ],
            json_decode($transport->sent[0]['payload'], true, 512, \JSON_THROW_ON_ERROR),
        );
    }

    // ------------------------------------------ what an operator debugs with

    public function testTheSuccessLogSaysWhichWebhookWentWhereAndHow(): void
    {
        $this->subscribe();

        $this->dispatcherWith(new FakeWebhookTransport([200]))->dispatch('event.created', ['id' => 1]);

        $delivered = array_values(array_filter(
            $this->logger->records,
            static fn(array $r): bool => $r['message'] === 'Webhook delivered',
        ));

        self::assertCount(1, $delivered);
        self::assertSame(
            ['webhook_id' => $this->repo->findAll()[0]->id(), 'url' => 'https://hooks.example.com/hook', 'status' => 200],
            $delivered[0]['context'],
        );
    }

    public function testTheBlockedLogSaysWhichUrlAndWhy(): void
    {
        $this->repo->save(new WebhookSubscription(0, 'https://blocked.example.com/hook', '*', 'secret', true));

        $this->dispatcherWith(new FakeWebhookTransport([200], 'https://blocked.example.com/hook'))
            ->dispatch('event.created', ['id' => 1]);

        $blocked = array_values(array_filter(
            $this->logger->records,
            static fn(array $r): bool => $r['message'] === 'Webhook delivery blocked',
        ));

        self::assertNotSame([], $blocked);
        self::assertSame('https://blocked.example.com/hook', $blocked[0]['context']['url']);
        self::assertStringContainsString('private address', (string) $blocked[0]['context']['reason']);
    }

    public function testTheDeliveryLogReadsBackAsNumbersOnAStringifyingDriver(): void
    {
        // MySQL's PDO returns every column as a string by default, which is
        // what the int casts in getDeliveryLog() are for. On SQLite they look
        // like no-ops, so the casts could be dropped and this suite would
        // still pass while the real driver started handing "500" to callers
        // that compare it with ===.
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, true);

        $repo = new WebhookRepository($pdo);
        $id = $repo->save(new WebhookSubscription(0, 'https://hooks.example.com/hook', '*', 'secret', true));
        $dispatcher = new WebhookDispatcher(
            $repo,
            $pdo,
            new FakeWebhookTransport([500, 500, 500]),
            $this->logger,
            null,
            retryDelays: [0, 0, 0],
        );

        $dispatcher->dispatch('event.created', ['id' => 1]);
        $row = $dispatcher->getDeliveryLog($id)[0];

        self::assertSame($id, $row['webhook_id']);
        self::assertSame(500, $row['status_code']);
    }
}

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param array<string, mixed> $context
     */
    #[\Override]
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
