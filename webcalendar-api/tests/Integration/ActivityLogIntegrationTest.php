<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\ActivityLogType;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;

final class ActivityLogIntegrationTest extends IntegrationTestCase
{
    public function testActivityLogServiceRecordsCreateEvent(): void
    {
        $eventService = $this->factory->getEventService();
        $logService = $this->factory->getActivityLogService();

        // Create an event
        $event = new Event(
            id: new EventId(0),
            uid: 'log-test-create@test',
            name: 'Log Test Event',
            description: '',
            location: '',
            start: new \DateTimeImmutable('2026-06-01 10:00:00'),
            duration: 60,
            createdBy: 'admin',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
        );
        $eventService->createEvent($event, $this->adminUser);

        $created = $this->factory->getEventRepository()->findByUid('log-test-create@test');
        $this->assertNotNull($created);

        // Log the creation (simulating what EventController does)
        $logService->log(
            $created->id()->value(),
            'admin',
            null,
            ActivityLogType::CREATE,
            'Created event: Log Test Event',
        );

        // Verify log entry exists
        $range = new DateRange(
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2027-01-01'),
        );
        $logs = $logService->getLogs($range, $this->adminUser, 'admin');

        $this->assertGreaterThan(0, \count($logs));
        $found = false;
        foreach ($logs as $entry) {
            if ($entry->entryId() === $created->id()->value() && $entry->type() === ActivityLogType::CREATE) {
                $found = true;
                $this->assertStringContainsString('Log Test Event', $entry->text());
                break;
            }
        }
        $this->assertTrue($found, 'Activity log entry for CREATE not found');
    }

    public function testActivityLogServiceRecordsUpdateEvent(): void
    {
        $eventService = $this->factory->getEventService();
        $logService = $this->factory->getActivityLogService();

        $event = new Event(
            id: new EventId(0),
            uid: 'log-test-update@test',
            name: 'Update Me',
            description: '',
            location: '',
            start: new \DateTimeImmutable('2026-06-01 14:00:00'),
            duration: 30,
            createdBy: 'admin',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
        );
        $eventService->createEvent($event, $this->adminUser);

        $created = $this->factory->getEventRepository()->findByUid('log-test-update@test');
        $this->assertNotNull($created);

        // Log update
        $logService->log(
            $created->id()->value(),
            'admin',
            null,
            ActivityLogType::UPDATE,
            'Updated event: Update Me',
        );

        $range = new DateRange(
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2027-01-01'),
        );
        $logs = $logService->getLogs($range, $this->adminUser, 'admin');

        $updateFound = false;
        foreach ($logs as $entry) {
            if ($entry->type() === ActivityLogType::UPDATE && str_contains($entry->text(), 'Update Me')) {
                $updateFound = true;
                break;
            }
        }
        $this->assertTrue($updateFound, 'Activity log entry for UPDATE not found');
    }

    public function testActivityLogServiceRecordsDeleteEvent(): void
    {
        $logService = $this->factory->getActivityLogService();

        // Log a delete (event may already be gone)
        $logService->log(999, 'admin', null, ActivityLogType::UPDATE, 'Deleted event: Gone Event');

        $range = new DateRange(
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2027-01-01'),
        );
        $logs = $logService->getLogs($range, $this->adminUser, 'admin');

        $deleteFound = false;
        foreach ($logs as $entry) {
            if (str_contains($entry->text(), 'Deleted event: Gone Event')) {
                $deleteFound = true;
                break;
            }
        }
        $this->assertTrue($deleteFound, 'Activity log entry for DELETE not found');
    }
}
