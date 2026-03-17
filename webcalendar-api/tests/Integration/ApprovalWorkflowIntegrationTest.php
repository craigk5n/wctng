<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;

final class ApprovalWorkflowIntegrationTest extends IntegrationTestCase
{
    public function testApprovalWorkflowFullFlow(): void
    {
        $eventService = $this->factory->getEventService();
        $eventRepo = $this->factory->getEventRepository();

        // Create event with needs_approval status
        $event = new Event(
            id: new EventId(0),
            uid: 'approval-test@test',
            name: 'Pending Event',
            description: '',
            location: '',
            start: new \DateTimeImmutable('2026-06-01 10:00:00'),
            duration: 60,
            createdBy: 'alice',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
            status: 'needs_approval',
        );
        $eventService->createEvent($event, $this->normalUser);

        // Find the created event
        $created = $eventRepo->findByUid('approval-test@test');
        $this->assertNotNull($created);
        $this->assertSame('needs_approval', $created->status());

        // Verify it shows up in pending
        $pending = $eventRepo->findByStatus('needs_approval');
        $pendingNames = array_map(fn ($e) => $e->name(), $pending);
        $this->assertContains('Pending Event', $pendingNames);

        // Approve it
        $approved = new Event(
            id: $created->id(),
            uid: $created->uid(),
            name: $created->name(),
            description: $created->description(),
            location: $created->location(),
            start: $created->start(),
            duration: $created->duration(),
            createdBy: $created->createdBy(),
            type: $created->type(),
            access: $created->access(),
            status: 'confirmed',
        );
        $eventRepo->save($approved);

        // Verify status changed
        $refetched = $eventService->getEventById($created->id());
        $this->assertNotNull($refetched);
        $this->assertSame('confirmed', $refetched->status());

        // No longer in pending
        $pending = $eventRepo->findByStatus('needs_approval');
        $pendingNames = array_map(fn ($e) => $e->name(), $pending);
        $this->assertNotContains('Pending Event', $pendingNames);
    }
}
