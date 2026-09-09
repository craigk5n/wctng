<?php

declare(strict_types=1);

namespace App\Tests\Unit\Webhook;

use App\Security\OutboundUrlValidator;
use App\Webhook\WebhookDispatcher;
use App\Webhook\WebhookRepository;
use App\Webhook\WebhookSubscription;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Covers what the dispatcher decides around a delivery, as opposed to whether
 * curl itself works: retry count, attempt numbering, log mapping, pruning, and
 * that a non-matching subscription does not stop the ones behind it.
 *
 * Deliveries go to a closed port, so every attempt returns status 0. That
 * drives the failure path; the 2xx branch is unreachable without a real HTTP
 * transport, so the success-range comparison stays uncovered.
 */
final class WebhookDeliveryPolicyTest extends TestCase
{
    private \PDO $pdo;
    private WebhookRepository $repo;
    private RecordingLogger $logger;
    private WebhookDispatcher $dispatcher;

    #[\Override]
    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->repo = new WebhookRepository($this->pdo);
        $this->logger = new RecordingLogger();
        $this->dispatcher = new WebhookDispatcher(
            $this->repo,
            $this->pdo,
            new OutboundUrlValidator('standalone'),
            $this->logger,
            null,
            retryDelays: [0, 0, 0],
        );
    }

    private function subscribe(string $events = '*', string $host = '127.0.0.1:19999'): int
    {
        return $this->repo->save(
            new WebhookSubscription(0, 'http://' . $host . '/hook', $events, 'secret', true)
        );
    }

    public function testDispatchSkipsUnsubscribedWebhookButKeepsGoing(): void
    {
        // `continue` rather than `break`: a subscription that does not want
        // this event must not stop the ones after it from being delivered.
        $this->repo->save(new WebhookSubscription(0, 'http://127.0.0.1:19999/a', 'event.deleted', 's', true));
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
        self::assertSame(0, $failures[0]['context']['status']);
    }

    public function testDeliveryLogReadsBackTheStoredValues(): void
    {
        $id = $this->subscribe();

        $this->dispatcher->dispatch('event.created', ['id' => 1]);

        $row = $this->dispatcher->getDeliveryLog($id)[0];
        // Each of these is a ternary whose two branches were interchangeable
        // without any test noticing.
        self::assertSame($id, $row['webhook_id']);
        self::assertSame(0, $row['status_code'], 'a refused connection records 0');
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
