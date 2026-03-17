<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\ConflictDetectionService;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;

final class ConflictDetectionIntegrationTest extends IntegrationTestCase
{
    public function testDetectsOverlappingEventsFromDatabase(): void
    {
        $eventService = $this->factory->getEventService();

        // Create first event: 10:00-11:00
        $eventService->createEvent(new Event(
            id: new EventId(0),
            uid: 'conflict-1@test',
            name: 'Morning Meeting',
            description: '',
            location: '',
            start: new \DateTimeImmutable('2026-06-01 10:00:00'),
            duration: 60,
            createdBy: 'admin',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
        ), $this->adminUser);

        // Create overlapping event: 10:30-11:30
        $newEvent = new Event(
            id: new EventId(0),
            uid: 'conflict-2@test',
            name: 'Overlapping Meeting',
            description: '',
            location: '',
            start: new \DateTimeImmutable('2026-06-01 10:30:00'),
            duration: 60,
            createdBy: 'admin',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
        );

        // Fetch existing events from DB
        $range = new DateRange(
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-01 23:59:59'),
        );
        $existing = $eventService->getEventsInDateRange($range, $this->adminUser)->all();

        $conflictService = new ConflictDetectionService();
        $conflicts = $conflictService->findConflicts($newEvent, $existing);

        $this->assertCount(1, $conflicts);
        $this->assertSame('Morning Meeting', $conflicts[0]->name());
    }

    public function testNoConflictWithDifferentTimes(): void
    {
        $eventService = $this->factory->getEventService();

        $eventService->createEvent(new Event(
            id: new EventId(0),
            uid: 'no-conflict-1@test',
            name: 'Morning',
            description: '',
            location: '',
            start: new \DateTimeImmutable('2026-07-01 09:00:00'),
            duration: 60,
            createdBy: 'admin',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
        ), $this->adminUser);

        $newEvent = new Event(
            id: new EventId(0),
            uid: 'no-conflict-2@test',
            name: 'Afternoon',
            description: '',
            location: '',
            start: new \DateTimeImmutable('2026-07-01 14:00:00'),
            duration: 60,
            createdBy: 'admin',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
        );

        $range = new DateRange(
            new \DateTimeImmutable('2026-07-01'),
            new \DateTimeImmutable('2026-07-01 23:59:59'),
        );
        $existing = $eventService->getEventsInDateRange($range, $this->adminUser)->all();

        $conflictService = new ConflictDetectionService();
        $conflicts = $conflictService->findConflicts($newEvent, $existing);

        $this->assertCount(0, $conflicts);
    }
}
