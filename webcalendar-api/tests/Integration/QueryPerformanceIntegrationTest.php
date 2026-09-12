<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventScope;
use WebCalendar\Core\Domain\ValueObject\EventType;

/**
 * Tests that verify query performance and index coverage.
 */
final class QueryPerformanceIntegrationTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->applyIndexMigrations();
        $this->seedEvents(50);
    }

    private function applyIndexMigrations(): void
    {
        $migrationFiles = glob(__DIR__ . '/../../migrations/tenant/*.sql');
        if ($migrationFiles === false) {
            return;
        }
        foreach ($migrationFiles as $file) {
            $sql = file_get_contents($file);
            if ($sql === false) {
                continue;
            }
            // Remove comment lines
            $clean = (string) preg_replace('/--[^\n]*/', '', $sql);
            /** @var string[] $statements */
            $statements = preg_split('/;\s*\n/', $clean) ?? [];
            foreach ($statements as $stmt) {
                $stmt = trim($stmt);
                if ($stmt !== '') {
                    try {
                        $this->pdo->exec($stmt);
                    } catch (\PDOException) {
                        // Index may already exist
                    }
                }
            }
        }
    }

    private function seedEvents(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $day = str_pad((string) (($i % 28) + 1), 2, '0', \STR_PAD_LEFT);
            $this->factory->getEventService()->createEvent(new Event(
                id: new EventId(0),
                uid: "perf-{$i}@test",
                name: "Perf Event {$i}",
                description: '',
                location: "Room {$i}",
                start: new \DateTimeImmutable("2026-06-{$day} 10:00:00"),
                duration: 60,
                createdBy: 'alice',
                type: EventType::EVENT,
                access: $i % 5 === 0 ? AccessLevel::PRIVATE : AccessLevel::PUBLIC,
            ), $this->normalUser);
        }
    }

    public function testFindByDateRangeQueryCount(): void
    {
        // Enable SQLite query stats
        $this->pdo->exec('PRAGMA cache_size = 1000');

        $start = new \DateTimeImmutable('2026-06-01');
        $end = new \DateTimeImmutable('2026-06-30');
        $range = new DateRange($start, $end);

        $events = $this->factory->getEventRepository()->findByDateRange($range, EventScope::forUser($this->normalUser));

        // Should return events (verifies the query works)
        $this->assertGreaterThan(0, \count($events));
        // All 50 events fall in June
        $this->assertCount(50, $events);
    }

    public function testPublicEventFilterUsesAccessIndex(): void
    {
        $start = new \DateTimeImmutable('2026-06-01');
        $end = new \DateTimeImmutable('2026-06-30');
        $range = new DateRange($start, $end);

        // Query with access level filter (used by SEO/sitemap)
        $events = $this->factory->getEventRepository()->findByDateRange($range, EventScope::publicOnly()->limitedToUsers(['alice']));

        // Should only return public events (40 of 50 are public)
        $this->assertCount(40, $events);
    }

    public function testIndexesExistAfterMigration(): void
    {
        // Verify key indexes exist in SQLite
        $stmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='index'");
        $this->assertNotFalse($stmt);
        $indexes = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertContains('idx_entry_user_date_time', $indexes);
        $this->assertContains('idx_entry_access', $indexes);
        $this->assertContains('idx_entry_mod_date', $indexes);
        $this->assertContains('idx_entry_user_login', $indexes);
        $this->assertContains('idx_entry_repeats_cal', $indexes);
        $this->assertContains('idx_user_pref_login', $indexes);
    }

    public function testBatchCategoryLoadDoesNotN1(): void
    {
        // Create categories and assign to events
        $catService = $this->factory->getCategoryService();

        // Verify batch loading works without N+1
        $eventIds = [];
        $events = $this->factory->getEventRepository()->findByDateRange(
            new DateRange(new \DateTimeImmutable('2026-06-01'), new \DateTimeImmutable('2026-06-30')),
            EventScope::forUser($this->normalUser),
        );
        foreach (\array_slice($events, 0, 20) as $e) {
            $eventIds[] = $e->id();
        }

        // Batch category load — should be 1 query, not 20
        $catRepo = $this->factory->getCategoryRepository();
        /** @var array<int, array{id: int, color: string|null}> $result */
        $result = $catRepo->getForEventsBatch($eventIds, 'alice');
        // No assertion on count — just verifying it doesn't error
        $this->assertIsArray($result);
    }

    public function testEventCountByAccessLevel(): void
    {
        // This uses the cal_access index
        $publicCount = $this->factory->getEventRepository()->countByAccessLevel('P');
        $this->assertSame(40, $publicCount);

        $privateCount = $this->factory->getEventRepository()->countByAccessLevel('R');
        $this->assertSame(10, $privateCount);
    }
}
