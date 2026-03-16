<?php

declare(strict_types=1);

namespace App\Tests\Unit\DTO;

use App\DTO\EventResponseDTO;
use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;

final class EventResponseDTOTest extends TestCase
{
    public function testConvertsEntityToArray(): void
    {
        $event = $this->createEvent();
        $array = EventResponseDTO::fromEntity($event);

        $this->assertSame(42, $array['id']);
        $this->assertSame('Test Event', $array['title']);
        $this->assertSame('A description', $array['description']);
        $this->assertSame('20260315', $array['start_date']);
        $this->assertSame('100000', $array['start_time']);
        $this->assertSame(60, $array['duration']);
        $this->assertSame('Room A', $array['location']);
        $this->assertSame('P', $array['access']);
        $this->assertSame('E', $array['type']);
        $this->assertSame('admin', $array['created_by']);
        $this->assertFalse($array['all_day']);
    }

    public function testAllDayEventOutput(): void
    {
        $event = new Event(
            id: new EventId(1),
            uid: 'uid-allday',
            name: 'All Day',
            description: '',
            location: '',
            start: new \DateTimeImmutable('2026-03-15 00:00:00'),
            duration: 0,
            createdBy: 'admin',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
            allDay: true,
        );
        $array = EventResponseDTO::fromEntity($event);

        $this->assertTrue($array['all_day']);
        $this->assertSame('20260315', $array['start_date']);
        $this->assertNull($array['start_time']);
    }

    public function testFromCollectionConvertsMultipleEvents(): void
    {
        $events = [$this->createEvent(), $this->createEvent()];
        $result = EventResponseDTO::fromCollection($events);

        $this->assertCount(2, $result);
        $this->assertSame(42, $result[0]['id']);
        $this->assertSame(42, $result[1]['id']);
    }

    public function testEndDateAndTimeCalculated(): void
    {
        $event = $this->createEvent();
        $array = EventResponseDTO::fromEntity($event);

        // 10:00 + 60 minutes = 11:00
        $this->assertSame('20260315', $array['end_date']);
        $this->assertSame('110000', $array['end_time']);
    }

    private function createEvent(): Event
    {
        return new Event(
            id: new EventId(42),
            uid: 'uid-test-42',
            name: 'Test Event',
            description: 'A description',
            location: 'Room A',
            start: new \DateTimeImmutable('2026-03-15 10:00:00'),
            duration: 60,
            createdBy: 'admin',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
        );
    }
}
