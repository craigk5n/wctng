<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventScope;
use WebCalendar\Core\Domain\ValueObject\EventType;

final class EventCrudIntegrationTest extends IntegrationTestCase
{
    public function testCreateReadUpdateDelete(): void
    {
        $eventService = $this->factory->getEventService();

        // CREATE
        $event = new Event(
            id: new EventId(0),
            uid: 'crud-test@example.com',
            name: 'Integration Test Event',
            description: 'Created by integration test',
            location: 'Test Room',
            start: new \DateTimeImmutable('2026-06-01 10:00:00'),
            duration: 60,
            createdBy: 'admin',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
        );

        $eventService->createEvent($event, $this->adminUser);

        // READ
        $found = $this->factory->getEventRepository()->findByUid('crud-test@example.com');
        $this->assertNotNull($found);
        $this->assertSame('Integration Test Event', $found->name());
        $this->assertSame('Test Room', $found->location());
        $eventId = $found->id();

        // UPDATE
        $updated = new Event(
            id: $eventId,
            uid: $found->uid(),
            name: 'Updated Event',
            description: 'Updated description',
            location: 'New Room',
            start: $found->start(),
            duration: 90,
            createdBy: 'admin',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
            sequence: 1,
        );
        $eventService->updateEvent($updated, $this->adminUser);

        $refetched = $eventService->getEventById($eventId);
        $this->assertNotNull($refetched);
        $this->assertSame('Updated Event', $refetched->name());
        $this->assertSame(90, $refetched->duration());

        // DELETE
        $eventService->deleteEvent($eventId, $this->adminUser);
        $deleted = $eventService->getEventById($eventId);
        $this->assertNull($deleted);
    }

    public function testDateRangeQuery(): void
    {
        $eventService = $this->factory->getEventService();

        // Create events on different dates
        $eventService->createEvent(new Event(
            id: new EventId(0),
            uid: 'range-1@test',
            name: 'June Event',
            description: '',
            location: '',
            start: new \DateTimeImmutable('2026-06-15 09:00:00'),
            duration: 60,
            createdBy: 'admin',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
        ), $this->adminUser);

        $eventService->createEvent(new Event(
            id: new EventId(0),
            uid: 'range-2@test',
            name: 'July Event',
            description: '',
            location: '',
            start: new \DateTimeImmutable('2026-07-15 09:00:00'),
            duration: 60,
            createdBy: 'admin',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
        ), $this->adminUser);

        // Query June only
        $range = new DateRange(
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-30'),
        );
        $results = $eventService->getEventsInDateRange($range, EventScope::forUser($this->adminUser));
        $names = array_map(fn($e) => $e->name(), $results->all());

        $this->assertContains('June Event', $names);
        $this->assertNotContains('July Event', $names);
    }
}
