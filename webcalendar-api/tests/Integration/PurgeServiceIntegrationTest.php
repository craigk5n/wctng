<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\CalDavSyncTokenRepository;
use App\Service\CalendarPublisherInterface;
use App\Service\PurgeService;
use App\Webhook\WebhookDispatcherInterface;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;

/**
 * Recording double for WebhookDispatcherInterface: captures dispatch()
 * calls without making HTTP requests.
 */
final class RecordingWebhookDispatcher implements WebhookDispatcherInterface
{
    /** @var list<array{event:string,data:array<string,mixed>}> */
    public array $calls = [];

    public function dispatch(string $eventType, array $data): void
    {
        $this->calls[] = ['event' => $eventType, 'data' => $data];
    }
}

final class RecordingCalendarPublisher implements CalendarPublisherInterface
{
    /** @var list<array<string,mixed>> */
    public array $purgedCalls = [];

    public function publishCalendarPurged(array $payload): void
    {
        $this->purgedCalls[] = $payload;
    }
}

final class PurgeServiceIntegrationTest extends IntegrationTestCase
{
    private PurgeService $purge;
    private RecordingWebhookDispatcher $webhooks;
    private RecordingCalendarPublisher $mercure;
    private CalDavSyncTokenRepository $syncTokens;

    protected function setUp(): void
    {
        parent::setUp();
        $this->webhooks = new RecordingWebhookDispatcher();
        $this->mercure = new RecordingCalendarPublisher();
        $this->syncTokens = new CalDavSyncTokenRepository($this->pdo);
        $this->purge = new PurgeService(
            $this->pdo,
            $this->factory->getActivityLogRepository(),
            $this->webhooks,
            $this->mercure,
            $this->syncTokens,
        );
    }

    /** @return list<array<string,mixed>> */
    private function fetchPurgeLogEntries(): array
    {
        $rows = $this->pdo->query(
            'SELECT cal_login, cal_type, cal_text FROM webcal_entry_log WHERE cal_entry_id = 0 ORDER BY cal_log_id'
        )->fetchAll(\PDO::FETCH_ASSOC);
        return \is_array($rows) ? array_values($rows) : [];
    }

    private function createEvent(
        string $uid,
        string $date,
        string $creator = 'admin',
        int $duration = 60,
    ): int {
        $event = new Event(
            id: new EventId(0),
            uid: $uid,
            name: 'Event ' . $uid,
            description: '',
            location: '',
            start: new \DateTimeImmutable($date . ' 10:00:00'),
            duration: $duration,
            createdBy: $creator,
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
        );
        $this->factory->getEventService()->createEvent($event, $this->adminUser);
        $found = $this->factory->getEventRepository()->findByUid($uid);
        $this->assertNotNull($found);
        return $found->id()->value();
    }

    private function countEntries(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM webcal_entry')->fetchColumn();
    }

    public function testDryRunReturnsCountWithoutDeleting(): void
    {
        $this->createEvent('old-1@test', '2020-01-15');
        $this->createEvent('old-2@test', '2021-06-10');
        $this->createEvent('new-1@test', '2030-01-01');

        $result = $this->purge->purge(
            beforeDate: new \DateTimeImmutable('2025-01-01'),
            dryRun: true,
        );

        $this->assertSame(2, $result->count);
        $this->assertTrue($result->dryRun);
        $this->assertSame(3, $this->countEntries(), 'Dry run must not delete');
    }

    public function testPurgeDeletesEventsBeforeDate(): void
    {
        $oldId = $this->createEvent('old@test', '2020-01-15');
        $newId = $this->createEvent('new@test', '2030-01-01');

        $dry = $this->purge->purge(new \DateTimeImmutable('2025-01-01'), dryRun: true);
        $result = $this->purge->purge(
            beforeDate: new \DateTimeImmutable('2025-01-01'),
            dryRun: false,
            confirmCount: $dry->count,
        );

        $this->assertSame(1, $result->count);
        $this->assertFalse($result->dryRun);
        $this->assertSame(1, $this->countEntries());
        $this->assertNotNull($this->factory->getEventRepository()->findByUid('new@test'));
        $this->assertNull($this->factory->getEventRepository()->findByUid('old@test'));
    }

    public function testConfirmCountMismatchRejected(): void
    {
        $this->createEvent('a@test', '2020-01-15');
        $this->createEvent('b@test', '2020-02-15');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/confirm_count/i');

        $this->purge->purge(
            beforeDate: new \DateTimeImmutable('2025-01-01'),
            dryRun: false,
            confirmCount: 99,
        );
    }

    public function testLiveRunRequiresConfirmCount(): void
    {
        $this->createEvent('a@test', '2020-01-15');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/confirm_count/i');

        $this->purge->purge(
            beforeDate: new \DateTimeImmutable('2025-01-01'),
            dryRun: false,
            confirmCount: null,
        );
    }

    public function testPurgeScopedByUser(): void
    {
        $this->createEvent('admin-old@test', '2020-01-15', 'admin');
        $this->createEvent('alice-old@test', '2020-01-15', 'alice');

        $dry = $this->purge->purge(
            beforeDate: new \DateTimeImmutable('2025-01-01'),
            userLogin: 'alice',
            dryRun: true,
        );
        $this->assertSame(1, $dry->count);

        $result = $this->purge->purge(
            beforeDate: new \DateTimeImmutable('2025-01-01'),
            userLogin: 'alice',
            dryRun: false,
            confirmCount: 1,
        );
        $this->assertSame(1, $result->count);
        $this->assertNotNull($this->factory->getEventRepository()->findByUid('admin-old@test'));
        $this->assertNull($this->factory->getEventRepository()->findByUid('alice-old@test'));
    }

    public function testPurgeCascadesChildTables(): void
    {
        $eventId = $this->createEvent('cascade@test', '2020-01-15');

        // Add child rows across all cascade tables that exist in the schema.
        $this->pdo->exec("INSERT INTO webcal_entry_user (cal_id, cal_login, cal_status) VALUES ({$eventId}, 'alice', 'A')");
        $this->pdo->exec("INSERT INTO webcal_entry_categories (cal_id, cat_id, cat_order, cat_owner) VALUES ({$eventId}, 1, 0, 'admin')");
        // cal_end in the past → already-ended series → deleted (not truncated)
        $this->pdo->exec("INSERT INTO webcal_entry_repeats (cal_id, cal_type, cal_frequency, cal_end) VALUES ({$eventId}, 'daily', 1, 20200201)");
        $this->pdo->exec("INSERT INTO webcal_entry_repeats_not (cal_id, cal_date, cal_exdate) VALUES ({$eventId}, 20200116, 1)");
        $this->pdo->exec("INSERT INTO webcal_entry_ext_user (cal_id, cal_fullname, cal_email) VALUES ({$eventId}, 'Bob Ext', 'bob@ext.com')");
        $this->pdo->exec("INSERT INTO webcal_reminders (cal_id, cal_date, cal_offset) VALUES ({$eventId}, 20200115, 15)");
        $this->pdo->exec("INSERT INTO webcal_blob (cal_id, cal_login, cal_name, cal_type, cal_mod_date, cal_mod_time) VALUES ({$eventId}, 'admin', 'file.txt', 'A', 20200115, 100000)");

        // Event was made recurring via webcal_entry_repeats insert above, so
        // the purge must include repeating to reach it.
        $dry = $this->purge->purge(
            beforeDate: new \DateTimeImmutable('2025-01-01'),
            includeRepeating: true,
            dryRun: true,
        );
        $this->purge->purge(
            beforeDate: new \DateTimeImmutable('2025-01-01'),
            includeRepeating: true,
            dryRun: false,
            confirmCount: $dry->count,
        );

        $tables = [
            'webcal_entry',
            'webcal_entry_user',
            'webcal_entry_categories',
            'webcal_entry_repeats',
            'webcal_entry_repeats_not',
            'webcal_entry_ext_user',
            'webcal_reminders',
            'webcal_blob',
        ];
        foreach ($tables as $table) {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE cal_id = :id");
            $stmt->execute(['id' => $eventId]);
            $count = (int) $stmt->fetchColumn();
            $this->assertSame(0, $count, "Expected {$table} rows for event {$eventId} to be purged, found {$count}");
        }
    }

    public function testRepeatingEventsSkippedByDefault(): void
    {
        $singleId = $this->createEvent('single@test', '2020-01-15');
        $repeatingId = $this->createEvent('repeating@test', '2020-01-15');

        // Mark repeatingId as recurring by inserting a repeats row.
        $this->pdo->exec("INSERT INTO webcal_entry_repeats (cal_id, cal_type, cal_frequency) VALUES ({$repeatingId}, 'weekly', 1)");

        $dry = $this->purge->purge(
            beforeDate: new \DateTimeImmutable('2025-01-01'),
            includeRepeating: false,
            dryRun: true,
        );
        $this->assertSame(1, $dry->count, 'Repeating series should be skipped by default');

        $this->purge->purge(
            beforeDate: new \DateTimeImmutable('2025-01-01'),
            includeRepeating: false,
            dryRun: false,
            confirmCount: 1,
        );

        $this->assertNull($this->factory->getEventRepository()->findByUid('single@test'));
        $this->assertNotNull($this->factory->getEventRepository()->findByUid('repeating@test'));
    }

    public function testIncludeRepeatingTruncatesActiveSeriesWithUntil(): void
    {
        // Active series: started 2020, no end date → still recurring past cutoff.
        // Should be TRUNCATED (cal_end set to cutoff - 1), not deleted, so
        // historical occurrences are preserved.
        $repeatingId = $this->createEvent('active-series@test', '2020-01-15');
        $this->pdo->exec("INSERT INTO webcal_entry_repeats (cal_id, cal_type, cal_frequency, cal_end) VALUES ({$repeatingId}, 'weekly', 1, NULL)");

        $dry = $this->purge->purge(
            beforeDate: new \DateTimeImmutable('2025-01-01'),
            includeRepeating: true,
            dryRun: true,
        );
        $this->assertSame(1, $dry->count, 'Truncated series still counts as affected');

        $this->purge->purge(
            beforeDate: new \DateTimeImmutable('2025-01-01'),
            includeRepeating: true,
            dryRun: false,
            confirmCount: 1,
        );

        // Entry still exists (history preserved)
        $this->assertNotNull($this->factory->getEventRepository()->findByUid('active-series@test'));

        // Repeat row's cal_end is now set to cutoff - 1 day = 20241231
        $stmt = $this->pdo->prepare('SELECT cal_end FROM webcal_entry_repeats WHERE cal_id = :id');
        $stmt->execute(['id' => $repeatingId]);
        $this->assertSame(20241231, (int) $stmt->fetchColumn());
    }

    public function testIncludeRepeatingDeletesAlreadyEndedSeries(): void
    {
        // Series that already ended before the cutoff → fully delete.
        $endedId = $this->createEvent('ended-series@test', '2020-01-15');
        $this->pdo->exec("INSERT INTO webcal_entry_repeats (cal_id, cal_type, cal_frequency, cal_end) VALUES ({$endedId}, 'weekly', 1, 20220101)");

        $dry = $this->purge->purge(
            beforeDate: new \DateTimeImmutable('2025-01-01'),
            includeRepeating: true,
            dryRun: true,
        );
        $this->assertSame(1, $dry->count);

        $this->purge->purge(
            beforeDate: new \DateTimeImmutable('2025-01-01'),
            includeRepeating: true,
            dryRun: false,
            confirmCount: 1,
        );

        $this->assertNull($this->factory->getEventRepository()->findByUid('ended-series@test'));

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM webcal_entry_repeats WHERE cal_id = :id');
        $stmt->execute(['id' => $endedId]);
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testIncludeRepeatingMixedBatch(): void
    {
        // Three events: one non-recurring (delete), one active series (truncate),
        // one ended series (delete). All three are "affected".
        $plainId = $this->createEvent('plain@test', '2020-01-15');
        $activeId = $this->createEvent('active@test', '2020-01-15');
        $this->pdo->exec("INSERT INTO webcal_entry_repeats (cal_id, cal_type, cal_frequency) VALUES ({$activeId}, 'weekly', 1)");
        $endedId = $this->createEvent('ended@test', '2020-01-15');
        $this->pdo->exec("INSERT INTO webcal_entry_repeats (cal_id, cal_type, cal_frequency, cal_end) VALUES ({$endedId}, 'weekly', 1, 20210101)");

        $this->purge->purge(
            beforeDate: new \DateTimeImmutable('2025-01-01'),
            includeRepeating: true,
            dryRun: false,
            confirmCount: 3,
        );

        $this->assertNull($this->factory->getEventRepository()->findByUid('plain@test'));
        $this->assertNull($this->factory->getEventRepository()->findByUid('ended@test'));
        $this->assertNotNull($this->factory->getEventRepository()->findByUid('active@test'));

        $stmt = $this->pdo->prepare('SELECT cal_end FROM webcal_entry_repeats WHERE cal_id = :id');
        $stmt->execute(['id' => $activeId]);
        $this->assertSame(20241231, (int) $stmt->fetchColumn());
    }

    public function testLiveRunBumpsCalDavSyncTokenForAffectedUser(): void
    {
        $this->createEvent('admin-old@test', '2020-01-15', 'admin');
        $this->assertNull($this->syncTokens->getOverride('admin'), 'no override before purge');

        $this->purge->purge(
            beforeDate: new \DateTimeImmutable('2025-01-01'),
            actor: 'admin',
            dryRun: false,
            confirmCount: 1,
        );

        $override = $this->syncTokens->getOverride('admin');
        $this->assertNotNull($override);
        $this->assertGreaterThan(0, $override);
    }

    public function testPurgeOnlyBumpsAffectedUsers(): void
    {
        $this->createEvent('alice-old@test', '2020-01-15', 'alice');
        $this->createEvent('admin-future@test', '2030-01-01', 'admin');

        $this->purge->purge(
            beforeDate: new \DateTimeImmutable('2025-01-01'),
            actor: 'admin',
            dryRun: false,
            confirmCount: 1,
        );

        $this->assertNotNull($this->syncTokens->getOverride('alice'), 'alice was purged, should bump');
        $this->assertNull($this->syncTokens->getOverride('admin'), 'admin was untouched, no bump');
    }

    public function testDryRunDoesNotBumpSyncToken(): void
    {
        $this->createEvent('old@test', '2020-01-15', 'admin');

        $this->purge->purge(
            beforeDate: new \DateTimeImmutable('2025-01-01'),
            actor: 'admin',
            dryRun: true,
        );

        $this->assertNull($this->syncTokens->getOverride('admin'));
    }

    public function testTruncatedSeriesAlsoBumpsSyncToken(): void
    {
        // Active recurring series: include_repeating=true truncates (not deletes)
        // but the user's sync view still changed — token must bump.
        $rid = $this->createEvent('active@test', '2020-01-15', 'alice');
        $this->pdo->exec("INSERT INTO webcal_entry_repeats (cal_id, cal_type, cal_frequency) VALUES ({$rid}, 'weekly', 1)");

        $this->purge->purge(
            beforeDate: new \DateTimeImmutable('2025-01-01'),
            includeRepeating: true,
            actor: 'admin',
            dryRun: false,
            confirmCount: 1,
        );

        $this->assertNotNull($this->syncTokens->getOverride('alice'));
    }

    public function testLiveRunPublishesMercurePurged(): void
    {
        $this->createEvent('old-1@test', '2020-01-15');
        $this->createEvent('old-2@test', '2020-02-15');

        $this->purge->purge(
            beforeDate: new \DateTimeImmutable('2025-01-01'),
            actor: 'admin',
            dryRun: false,
            confirmCount: 2,
        );

        $this->assertCount(1, $this->mercure->purgedCalls, 'Exactly one Mercure calendar.purged message');
        $payload = $this->mercure->purgedCalls[0];
        $this->assertSame(2, $payload['count']);
        $this->assertSame('2025-01-01', $payload['before_date']);
        $this->assertSame('admin', $payload['actor']);
    }

    public function testMercureDryRunSilent(): void
    {
        $this->createEvent('old@test', '2020-01-15');

        $this->purge->purge(
            beforeDate: new \DateTimeImmutable('2025-01-01'),
            actor: 'admin',
            dryRun: true,
        );

        $this->assertSame([], $this->mercure->purgedCalls);
    }

    public function testMercureZeroCountSilent(): void
    {
        $this->createEvent('future@test', '2030-01-01');

        $this->purge->purge(
            beforeDate: new \DateTimeImmutable('2025-01-01'),
            actor: 'admin',
            dryRun: false,
            confirmCount: 0,
        );

        $this->assertSame([], $this->mercure->purgedCalls);
    }

    public function testLiveRunDispatchesPurgedWebhook(): void
    {
        $this->createEvent('old-1@test', '2020-01-15');
        $this->createEvent('old-2@test', '2020-02-15');

        $this->purge->purge(
            beforeDate: new \DateTimeImmutable('2025-01-01'),
            actor: 'admin',
            dryRun: false,
            confirmCount: 2,
        );

        $this->assertCount(1, $this->webhooks->calls, 'Exactly one bulk webhook should fire');
        $call = $this->webhooks->calls[0];
        $this->assertSame('events.purged', $call['event']);
        $this->assertSame(2, $call['data']['count']);
        $this->assertSame('2025-01-01', $call['data']['before_date']);
        $this->assertNull($call['data']['user_login']);
        $this->assertSame('admin', $call['data']['actor']);
        $this->assertFalse($call['data']['include_repeating']);
    }

    public function testDryRunDoesNotDispatch(): void
    {
        $this->createEvent('old@test', '2020-01-15');

        $this->purge->purge(
            beforeDate: new \DateTimeImmutable('2025-01-01'),
            actor: 'admin',
            dryRun: true,
        );

        $this->assertSame([], $this->webhooks->calls);
    }

    public function testZeroCountDoesNotDispatch(): void
    {
        // Webhook subscribers care about state changes; an empty purge
        // has none. (The activity log still records the attempt.)
        $this->createEvent('future@test', '2030-01-01');

        $this->purge->purge(
            beforeDate: new \DateTimeImmutable('2025-01-01'),
            actor: 'admin',
            dryRun: false,
            confirmCount: 0,
        );

        $this->assertSame([], $this->webhooks->calls);
    }

    public function testWebhookPayloadIncludesUserScope(): void
    {
        $this->createEvent('alice-old@test', '2020-01-15', 'alice');

        $this->purge->purge(
            beforeDate: new \DateTimeImmutable('2025-01-01'),
            userLogin: 'alice',
            actor: 'admin',
            dryRun: false,
            confirmCount: 1,
        );

        $this->assertCount(1, $this->webhooks->calls);
        $this->assertSame('alice', $this->webhooks->calls[0]['data']['user_login']);
    }

    public function testLiveRunWritesActivityLogEntry(): void
    {
        $this->createEvent('old@test', '2020-01-15');

        $this->purge->purge(
            beforeDate: new \DateTimeImmutable('2025-01-01'),
            actor: 'admin',
            dryRun: false,
            confirmCount: 1,
        );

        $entries = $this->fetchPurgeLogEntries();
        $this->assertCount(1, $entries);
        $this->assertSame('admin', $entries[0]['cal_login']);
        $this->assertStringContainsString('purge', strtolower((string) $entries[0]['cal_text']));
        $this->assertStringContainsString('count=1', (string) $entries[0]['cal_text']);
        $this->assertStringContainsString('before=2025-01-01', (string) $entries[0]['cal_text']);
    }

    public function testLiveRunLogsUserScope(): void
    {
        $this->createEvent('alice-old@test', '2020-01-15', 'alice');
        $this->purge->purge(
            beforeDate: new \DateTimeImmutable('2025-01-01'),
            userLogin: 'alice',
            actor: 'admin',
            dryRun: false,
            confirmCount: 1,
        );

        $entries = $this->fetchPurgeLogEntries();
        $this->assertCount(1, $entries);
        $this->assertStringContainsString('user=alice', (string) $entries[0]['cal_text']);
    }

    public function testDryRunDoesNotLog(): void
    {
        $this->createEvent('old@test', '2020-01-15');

        $this->purge->purge(
            beforeDate: new \DateTimeImmutable('2025-01-01'),
            actor: 'admin',
            dryRun: true,
        );

        $this->assertCount(0, $this->fetchPurgeLogEntries());
    }

    public function testEmptyPurgeStillLogs(): void
    {
        // Even a zero-count live run is worth auditing (someone tried).
        $this->createEvent('future@test', '2030-01-01');

        $this->purge->purge(
            beforeDate: new \DateTimeImmutable('2025-01-01'),
            actor: 'admin',
            dryRun: false,
            confirmCount: 0,
        );

        $entries = $this->fetchPurgeLogEntries();
        $this->assertCount(1, $entries);
        $this->assertStringContainsString('count=0', (string) $entries[0]['cal_text']);
    }

    public function testPurgeEmptyNoOp(): void
    {
        $this->createEvent('future@test', '2030-01-01');

        $result = $this->purge->purge(
            beforeDate: new \DateTimeImmutable('2025-01-01'),
            dryRun: false,
            confirmCount: 0,
        );

        $this->assertSame(0, $result->count);
        $this->assertSame(1, $this->countEntries());
    }
}
