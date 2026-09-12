<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventScope;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\UserPreference;

final class PublicCalendarIntegrationTest extends IntegrationTestCase
{
    public function testPublicCalendarShowsOnlyPublicEvents(): void
    {
        $eventService = $this->factory->getEventService();

        // Create a public event
        $eventService->createEvent(new Event(
            id: new EventId(0),
            uid: 'public-1@test',
            name: 'Public Meeting',
            description: '',
            location: '',
            start: new \DateTimeImmutable('2026-06-01 10:00:00'),
            duration: 60,
            createdBy: 'alice',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
        ), $this->normalUser);

        // Create a private event
        $eventService->createEvent(new Event(
            id: new EventId(0),
            uid: 'private-1@test',
            name: 'Private Meeting',
            description: '',
            location: '',
            start: new \DateTimeImmutable('2026-06-01 14:00:00'),
            duration: 60,
            createdBy: 'alice',
            type: EventType::EVENT,
            access: AccessLevel::PRIVATE,
        ), $this->normalUser);

        // Query with access level 'P' (public only)
        $range = new DateRange(
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-30'),
        );
        $events = $this->factory->getEventRepository()->findByDateRange($range, EventScope::publicOnly()->limitedToUsers(['alice']));

        $names = array_map(fn($e) => $e->name(), $events);
        $this->assertContains('Public Meeting', $names);
        $this->assertNotContains('Private Meeting', $names);
    }

    public function testPublicCalendarPreference(): void
    {
        $userRepo = $this->factory->getUserRepository();

        // Not enabled by default
        $prefs = $userRepo->getPreferences('alice');
        $publicEnabled = false;
        foreach ($prefs as $p) {
            if ($p->key() === 'public_calendar_enabled' && $p->value() === 'Y') {
                $publicEnabled = true;
            }
        }
        $this->assertFalse($publicEnabled);

        // Enable
        $userRepo->savePreference('alice', new UserPreference('public_calendar_enabled', 'Y'));

        $prefs = $userRepo->getPreferences('alice');
        $publicEnabled = false;
        foreach ($prefs as $p) {
            if ($p->key() === 'public_calendar_enabled' && $p->value() === 'Y') {
                $publicEnabled = true;
            }
        }
        $this->assertTrue($publicEnabled);
    }
}
