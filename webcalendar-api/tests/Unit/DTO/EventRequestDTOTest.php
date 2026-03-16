<?php

declare(strict_types=1);

namespace App\Tests\Unit\DTO;

use App\DTO\EventRequestDTO;
use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\EventType;

final class EventRequestDTOTest extends TestCase
{
    public function testCreatesEventFromValidData(): void
    {
        $data = [
            'title' => 'Test Event',
            'start_date' => '20260315',
            'start_time' => '100000',
            'duration' => 60,
        ];
        $event = EventRequestDTO::toEntity($data, 'admin');

        $this->assertInstanceOf(Event::class, $event);
        $this->assertSame('Test Event', $event->name());
        $this->assertSame('2026-03-15', $event->start()->format('Y-m-d'));
        $this->assertSame('10:00:00', $event->start()->format('H:i:s'));
        $this->assertSame(60, $event->duration());
        $this->assertFalse($event->isAllDay());
    }

    public function testCreatesEventWithAllFields(): void
    {
        $data = [
            'title' => 'Full Event',
            'description' => 'A description',
            'start_date' => '20260401',
            'start_time' => '143000',
            'duration' => 90,
            'location' => 'Room B',
            'access' => 'C',
            'type' => 'E',
            'priority' => 3,
        ];
        $event = EventRequestDTO::toEntity($data, 'testuser');

        $this->assertSame('Full Event', $event->name());
        $this->assertSame('A description', $event->description());
        $this->assertSame('Room B', $event->location());
        $this->assertSame(AccessLevel::CONFIDENTIAL, $event->access());
        $this->assertSame(EventType::EVENT, $event->type());
        $this->assertSame('testuser', $event->createdBy());
        $this->assertSame(90, $event->duration());
    }

    public function testAllDayEventSetsAllDayFlag(): void
    {
        $data = ['title' => 'All Day', 'start_date' => '20260315'];
        $event = EventRequestDTO::toEntity($data, 'admin');

        $this->assertTrue($event->isAllDay());
        $this->assertSame('2026-03-15', $event->start()->format('Y-m-d'));
    }

    public function testThrowsOnMissingTitle(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('title');
        EventRequestDTO::toEntity(['start_date' => '20260315'], 'admin');
    }

    public function testThrowsOnMissingStartDate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('start_date');
        EventRequestDTO::toEntity(['title' => 'Test'], 'admin');
    }

    public function testDefaultAccessIsPublic(): void
    {
        $data = ['title' => 'Test', 'start_date' => '20260315'];
        $event = EventRequestDTO::toEntity($data, 'admin');

        $this->assertSame(AccessLevel::PUBLIC, $event->access());
    }

    public function testDefaultTypeIsEvent(): void
    {
        $data = ['title' => 'Test', 'start_date' => '20260315'];
        $event = EventRequestDTO::toEntity($data, 'admin');

        $this->assertSame(EventType::EVENT, $event->type());
    }

    public function testDefaultDurationIsZero(): void
    {
        $data = ['title' => 'Test', 'start_date' => '20260315', 'start_time' => '100000'];
        $event = EventRequestDTO::toEntity($data, 'admin');

        $this->assertSame(0, $event->duration());
    }
}
