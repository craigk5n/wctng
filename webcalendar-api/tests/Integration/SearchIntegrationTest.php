<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;

final class SearchIntegrationTest extends IntegrationTestCase
{
    public function testSearchByKeyword(): void
    {
        $eventService = $this->factory->getEventService();

        $eventService->createEvent(new Event(
            id: new EventId(0),
            uid: 'search-1@test',
            name: 'Budget Planning Meeting',
            description: 'Q3 budget review',
            location: '',
            start: new \DateTimeImmutable('2026-06-01 10:00:00'),
            duration: 60,
            createdBy: 'admin',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
        ), $this->adminUser);

        $eventService->createEvent(new Event(
            id: new EventId(0),
            uid: 'search-2@test',
            name: 'Team Standup',
            description: 'Daily standup',
            location: '',
            start: new \DateTimeImmutable('2026-06-01 09:00:00'),
            duration: 15,
            createdBy: 'admin',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
        ), $this->adminUser);

        $results = $this->factory->getEventRepository()->search('budget', null, $this->adminUser, null, 10);
        $names = array_map(fn ($e) => $e->name(), $results->all());

        $this->assertContains('Budget Planning Meeting', $names);
        $this->assertNotContains('Team Standup', $names);
    }

    public function testSearchReturnsEmptyForNoMatch(): void
    {
        $results = $this->factory->getEventRepository()->search('xyznonexistent', null, $this->adminUser, null, 10);
        $this->assertCount(0, $results->all());
    }
}
