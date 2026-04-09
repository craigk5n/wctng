<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\PurgeService;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;

final class PurgeServiceIntegrationTest extends IntegrationTestCase
{
    private PurgeService $purge;

    protected function setUp(): void
    {
        parent::setUp();
        $this->purge = new PurgeService($this->pdo);
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
        $this->pdo->exec("INSERT INTO webcal_entry_repeats (cal_id, cal_type, cal_frequency) VALUES ({$eventId}, 'daily', 1)");
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

    public function testIncludeRepeatingDeletesRecurringSeries(): void
    {
        $repeatingId = $this->createEvent('repeating@test', '2020-01-15');
        $this->pdo->exec("INSERT INTO webcal_entry_repeats (cal_id, cal_type, cal_frequency) VALUES ({$repeatingId}, 'weekly', 1)");

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

        $this->assertNull($this->factory->getEventRepository()->findByUid('repeating@test'));

        // webcal_entry_repeats cascade also purged
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM webcal_entry_repeats WHERE cal_id = :id');
        $stmt->execute(['id' => $repeatingId]);
        $this->assertSame(0, (int) $stmt->fetchColumn());
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
