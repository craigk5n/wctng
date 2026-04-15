<?php

declare(strict_types=1);

namespace App\Service;

use WebCalendar\Core\Domain\Entity\Event;

/**
 * Detects time overlaps between events for the same user.
 */
final class ConflictDetectionService
{
    /**
     * Finds existing events that overlap with the given event.
     *
     * @param Event   $event      The event to check for conflicts
     * @param Event[] $existing   Existing events in the same date range
     * @param int|null $excludeId Event ID to exclude (for updates — don't conflict with self)
     *
     * @return Event[] Conflicting events
     */
    public function findConflicts(Event $event, array $existing, ?int $excludeId = null): array
    {
        $conflicts = [];

        foreach ($existing as $other) {
            // Skip self (when updating an event)
            if ($excludeId !== null && $other->id()->value() === $excludeId) {
                continue;
            }

            // Only check events from the same user
            if ($other->createdBy() !== $event->createdBy()) {
                continue;
            }

            if ($this->overlaps($event, $other)) {
                $conflicts[] = $other;
            }
        }

        return $conflicts;
    }

    /**
     * Formats conflicting events for API response.
     *
     * @param Event[] $conflicts
     * @return list<array{id: int, title: string, start: string, end: string}>
     */
    public function formatConflicts(array $conflicts): array
    {
        return array_values(array_map(
            static fn(Event $e): array => [
                'id' => $e->id()->value(),
                'title' => $e->name(),
                'start' => $e->start()->format('Y-m-d\TH:i:s'),
                'end' => $e->end()->format('Y-m-d\TH:i:s'),
            ],
            $conflicts,
        ));
    }

    private function overlaps(Event $a, Event $b): bool
    {
        // All-day events are treated as day-banners, not blocking time slots
        // (matches Google/Apple Calendar behavior). They never raise conflicts —
        // neither with other all-day events nor with timed events on the same day.
        if ($a->isAllDay() || $b->isAllDay()) {
            return false;
        }

        // Timed events: overlap if A starts before B ends AND B starts before A ends
        $aStart = $a->start();
        $aEnd = $a->end();
        $bStart = $b->start();
        $bEnd = $b->end();

        return $aStart < $bEnd && $bStart < $aEnd;
    }
}
