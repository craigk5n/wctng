<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\ConflictDetectionService;
use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;

final class ConflictDetectionServiceTest extends TestCase
{
    private function makeEvent(
        int $id,
        string $startDateTime,
        int $durationMinutes,
        string $createdBy = 'alice',
        bool $allDay = false,
    ): Event {
        return new Event(
            id: new EventId($id),
            uid: "event-{$id}@test",
            name: "Event {$id}",
            description: '',
            location: '',
            start: new \DateTimeImmutable($startDateTime),
            duration: $durationMinutes,
            createdBy: $createdBy,
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
            allDay: $allDay,
        );
    }

    public function testDetectsOverlappingEvents(): void
    {
        $service = new ConflictDetectionService();

        // Existing: 10:00-11:00
        $existing = [$this->makeEvent(1, '2026-04-01 10:00', 60)];
        // New: 10:30-11:30 (overlaps)
        $newEvent = $this->makeEvent(0, '2026-04-01 10:30', 60);

        $conflicts = $service->findConflicts($newEvent, $existing);
        $this->assertCount(1, $conflicts);
        $this->assertSame(1, $conflicts[0]->id()->value());
    }

    public function testNoConflictWhenNotOverlapping(): void
    {
        $service = new ConflictDetectionService();

        // Existing: 10:00-11:00
        $existing = [$this->makeEvent(1, '2026-04-01 10:00', 60)];
        // New: 11:00-12:00 (starts exactly when existing ends — no overlap)
        $newEvent = $this->makeEvent(0, '2026-04-01 11:00', 60);

        $conflicts = $service->findConflicts($newEvent, $existing);
        $this->assertCount(0, $conflicts);
    }

    public function testNewEventInsideExisting(): void
    {
        $service = new ConflictDetectionService();

        // Existing: 09:00-12:00
        $existing = [$this->makeEvent(1, '2026-04-01 09:00', 180)];
        // New: 10:00-10:30 (entirely inside existing)
        $newEvent = $this->makeEvent(0, '2026-04-01 10:00', 30);

        $conflicts = $service->findConflicts($newEvent, $existing);
        $this->assertCount(1, $conflicts);
    }

    public function testExistingInsideNew(): void
    {
        $service = new ConflictDetectionService();

        // Existing: 10:00-10:30
        $existing = [$this->makeEvent(1, '2026-04-01 10:00', 30)];
        // New: 09:00-12:00 (existing entirely inside new)
        $newEvent = $this->makeEvent(0, '2026-04-01 09:00', 180);

        $conflicts = $service->findConflicts($newEvent, $existing);
        $this->assertCount(1, $conflicts);
    }

    public function testAllDayEventsConflictOnSameDate(): void
    {
        $service = new ConflictDetectionService();

        // Existing: all-day on April 1
        $existing = [$this->makeEvent(1, '2026-04-01 00:00', 0, 'alice', true)];
        // New: all-day on April 1
        $newEvent = $this->makeEvent(0, '2026-04-01 00:00', 0, 'alice', true);

        $conflicts = $service->findConflicts($newEvent, $existing);
        $this->assertCount(1, $conflicts);
    }

    public function testAllDayEventsDifferentDatesNoConflict(): void
    {
        $service = new ConflictDetectionService();

        $existing = [$this->makeEvent(1, '2026-04-01 00:00', 0, 'alice', true)];
        $newEvent = $this->makeEvent(0, '2026-04-02 00:00', 0, 'alice', true);

        $conflicts = $service->findConflicts($newEvent, $existing);
        $this->assertCount(0, $conflicts);
    }

    public function testExcludesEventById(): void
    {
        $service = new ConflictDetectionService();

        // Same time, but exclude_id matches — should not conflict (updating same event)
        $existing = [$this->makeEvent(5, '2026-04-01 10:00', 60)];
        $newEvent = $this->makeEvent(5, '2026-04-01 10:00', 60);

        $conflicts = $service->findConflicts($newEvent, $existing, 5);
        $this->assertCount(0, $conflicts);
    }

    public function testMultipleConflicts(): void
    {
        $service = new ConflictDetectionService();

        $existing = [
            $this->makeEvent(1, '2026-04-01 10:00', 60),
            $this->makeEvent(2, '2026-04-01 10:30', 60),
            $this->makeEvent(3, '2026-04-01 14:00', 60), // No overlap
        ];
        $newEvent = $this->makeEvent(0, '2026-04-01 10:15', 45);

        $conflicts = $service->findConflicts($newEvent, $existing);
        $this->assertCount(2, $conflicts);
    }

    public function testOnlyChecksEventsFromSameUser(): void
    {
        $service = new ConflictDetectionService();

        // Existing from different user — should not conflict
        $existing = [$this->makeEvent(1, '2026-04-01 10:00', 60, 'bob')];
        $newEvent = $this->makeEvent(0, '2026-04-01 10:00', 60, 'alice');

        $conflicts = $service->findConflicts($newEvent, $existing);
        $this->assertCount(0, $conflicts);
    }

    public function testConflictResponseFormat(): void
    {
        $service = new ConflictDetectionService();

        $existing = [$this->makeEvent(1, '2026-04-01 10:00', 60)];
        $newEvent = $this->makeEvent(0, '2026-04-01 10:30', 60);

        $conflicts = $service->findConflicts($newEvent, $existing);
        $formatted = $service->formatConflicts($conflicts);

        $this->assertCount(1, $formatted);
        $this->assertArrayHasKey('id', $formatted[0]);
        $this->assertArrayHasKey('title', $formatted[0]);
        $this->assertArrayHasKey('start', $formatted[0]);
        $this->assertArrayHasKey('end', $formatted[0]);
    }
}
