<?php

declare(strict_types=1);

namespace App\DTO;

use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\Recurrence;
use WebCalendar\Core\Domain\ValueObject\RecurrenceRule;

/**
 * Maps JSON request data to webcalendar-core Event entities.
 */
final class EventRequestDTO
{
    /**
     * Creates a new Event entity from a JSON request array.
     *
     * @param array<string, mixed> $data      Request body fields
     * @param string               $createdBy Login of the authenticated user
     * @param int                  $id        Event ID (0 for new events)
     *
     * @throws \InvalidArgumentException If required fields are missing
     */
    public static function toEntity(array $data, string $createdBy, int $id = 0): Event
    {
        $title = self::requireString($data, 'title');
        $startDateStr = self::requireString($data, 'start_date');

        $description = self::optionalString($data, 'description', '');
        $location = self::optionalString($data, 'location', '');
        $duration = self::optionalInt($data, 'duration', 0);
        $startTimeStr = self::optionalString($data, 'start_time', '');
        $accessStr = self::optionalString($data, 'access', 'P');
        $typeStr = self::optionalString($data, 'type', 'E');

        $allDay = $startTimeStr === '';
        $start = self::parseStartDateTime($startDateStr, $startTimeStr);
        $access = AccessLevel::tryFrom($accessStr) ?? AccessLevel::PUBLIC;
        $type = EventType::tryFrom($typeStr) ?? EventType::EVENT;
        $rruleStr = self::optionalString($data, 'rrule', '');
        $recurrence = self::buildRecurrence($rruleStr);
        if ($rruleStr !== '') {
            $type = EventType::REPEATING_EVENT;
        }

        return new Event(
            id: new EventId($id),
            uid: self::generateUid(),
            name: $title,
            description: $description,
            location: $location,
            start: $start,
            duration: $duration,
            createdBy: $createdBy,
            type: $type,
            access: $access,
            recurrence: $recurrence,
            allDay: $allDay,
        );
    }

    /**
     * Applies partial update data to an existing Event, returning a new Event instance.
     *
     * @param array<string, mixed> $data  Fields to update (only present fields are applied)
     * @param Event                $event Existing event to update
     */
    public static function applyUpdate(array $data, Event $event): Event
    {
        $title = self::optionalString($data, 'title', $event->name());
        $description = self::optionalString($data, 'description', $event->description());
        $location = self::optionalString($data, 'location', $event->location());
        $duration = self::optionalInt($data, 'duration', $event->duration());

        $startDateStr = self::optionalString($data, 'start_date', '');
        $startTimeStr = self::optionalString($data, 'start_time', '');

        if ($startDateStr !== '') {
            $allDay = $startTimeStr === '' && !isset($data['start_time']);
            $start = self::parseStartDateTime($startDateStr, $startTimeStr);
        } else {
            $start = $event->start();
            $allDay = $event->isAllDay();
        }

        $accessStr = self::optionalString($data, 'access', $event->access()->value);
        $typeStr = self::optionalString($data, 'type', $event->type()->value);

        return new Event(
            id: $event->id(),
            uid: $event->uid(),
            name: $title,
            description: $description,
            location: $location,
            start: $start,
            duration: $duration,
            createdBy: $event->createdBy(),
            type: EventType::tryFrom($typeStr) ?? $event->type(),
            access: AccessLevel::tryFrom($accessStr) ?? $event->access(),
            recurrence: isset($data['rrule']) ? self::buildRecurrence(self::optionalString($data, 'rrule', '')) : $event->recurrence(),
            allDay: $allDay,
            sequence: $event->sequence() + 1,
        );
    }

    private static function buildRecurrence(string $rruleStr): Recurrence
    {
        if ($rruleStr === '') {
            return new Recurrence();
        }
        try {
            return new Recurrence(new RecurrenceRule($rruleStr));
        } catch (\InvalidArgumentException) {
            return new Recurrence();
        }
    }

    private static function parseStartDateTime(string $dateStr, string $timeStr): \DateTimeImmutable
    {
        if ($timeStr !== '') {
            $formatted = sprintf(
                '%s-%s-%s %s:%s:%s',
                substr($dateStr, 0, 4),
                substr($dateStr, 4, 2),
                substr($dateStr, 6, 2),
                substr($timeStr, 0, 2),
                substr($timeStr, 2, 2),
                substr($timeStr, 4, 2),
            );
        } else {
            $formatted = sprintf(
                '%s-%s-%s 00:00:00',
                substr($dateStr, 0, 4),
                substr($dateStr, 4, 2),
                substr($dateStr, 6, 2),
            );
        }

        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $formatted);
        if ($dt === false) {
            throw new \InvalidArgumentException("Invalid date/time: {$dateStr} {$timeStr}");
        }

        return $dt;
    }

    private static function generateUid(): string
    {
        return sprintf('wctng-%s@webcalendar', bin2hex(random_bytes(16)));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws \InvalidArgumentException
     */
    private static function requireString(array $data, string $key): string
    {
        if (!isset($data[$key]) || !\is_string($data[$key]) || $data[$key] === '') {
            throw new \InvalidArgumentException("Missing required field: {$key}");
        }

        return $data[$key];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function optionalString(array $data, string $key, string $default): string
    {
        if (isset($data[$key]) && \is_string($data[$key])) {
            return $data[$key];
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function optionalInt(array $data, string $key, int $default): int
    {
        if (isset($data[$key]) && is_numeric($data[$key])) {
            return (int) $data[$key];
        }

        return $default;
    }
}
