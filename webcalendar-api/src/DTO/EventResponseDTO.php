<?php

declare(strict_types=1);

namespace App\DTO;

use WebCalendar\Core\Domain\Entity\Event;

/**
 * Maps webcalendar-core Event entities to JSON response arrays.
 */
final class EventResponseDTO
{
    /**
     * Converts a single Event entity to a response array.
     *
     * @param list<int> $categoryIds Optional category IDs for this event
     *
     * @return array<string, mixed>
     */
    public static function fromEntity(Event $event, array $categoryIds = []): array
    {
        $start = $event->start();
        $end = $event->end();
        $allDay = $event->isAllDay();

        return [
            'id' => $event->id()->value(),
            'uid' => $event->uid(),
            'title' => $event->name(),
            'description' => $event->description(),
            'start_date' => $start->format('Ymd'),
            'start_time' => $allDay ? null : $start->format('His'),
            'end_date' => $end->format('Ymd'),
            'end_time' => $allDay ? null : $end->format('His'),
            'duration' => $event->duration(),
            'location' => $event->location(),
            'access' => $event->access()->value,
            'type' => $event->type()->value,
            'created_by' => $event->createdBy(),
            'all_day' => $allDay,
            'sequence' => $event->sequence(),
            'status' => $event->status(),
            'rrule' => $event->recurrence()->rule() !== null ? $event->recurrence()->rule()->toString() : null,
            'categories' => $categoryIds,
        ];
    }

    /**
     * Converts a collection of Event entities to response arrays.
     *
     * @param list<Event>              $events
     * @param array<int, list<int>>    $categoryMap Map of event ID → category IDs
     *
     * @return list<array<string, mixed>>
     */
    public static function fromCollection(array $events, array $categoryMap = []): array
    {
        return array_map(
            static fn (Event $event): array => self::fromEntity(
                $event,
                $categoryMap[$event->id()->value()] ?? [],
            ),
            $events,
        );
    }
}
