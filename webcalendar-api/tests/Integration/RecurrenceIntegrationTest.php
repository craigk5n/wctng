<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\Recurrence;
use WebCalendar\Core\Domain\ValueObject\RecurrenceRule;

final class RecurrenceIntegrationTest extends IntegrationTestCase
{
    public function testCreateRecurringEvent(): void
    {
        $eventService = $this->factory->getEventService();

        $rrule = new RecurrenceRule('FREQ=WEEKLY;BYDAY=MO,WE,FR');
        $event = new Event(
            id: new EventId(0),
            uid: 'recurring-test@test',
            name: 'Standup',
            description: '',
            location: '',
            start: new \DateTimeImmutable('2026-06-01 09:00:00'),
            duration: 15,
            createdBy: 'admin',
            type: EventType::REPEATING_EVENT,
            access: AccessLevel::PUBLIC,
            recurrence: new Recurrence($rrule),
        );

        $eventService->createEvent($event, $this->adminUser);

        $created = $this->factory->getEventRepository()->findByUid('recurring-test@test');
        $this->assertNotNull($created);
        $this->assertSame(EventType::REPEATING_EVENT, $created->type());
        $this->assertNotNull($created->recurrence()->rule());
        $this->assertStringContainsString('WEEKLY', $created->recurrence()->rule()->toString());
    }

    public function testRecurrenceRuleParsing(): void
    {
        $rule = new RecurrenceRule('FREQ=DAILY;INTERVAL=2;COUNT=10');
        $str = $rule->toString();

        $this->assertStringContainsString('FREQ=DAILY', $str);
        $this->assertStringContainsString('INTERVAL=2', $str);
        $this->assertStringContainsString('COUNT=10', $str);
    }

    public function testInvalidRruleThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RecurrenceRule('INVALID_RRULE');
    }
}
