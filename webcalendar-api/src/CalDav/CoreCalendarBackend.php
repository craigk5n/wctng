<?php

declare(strict_types=1);

namespace App\CalDav;

use Sabre\CalDAV\Backend\BackendInterface;
use Sabre\DAV\PropPatch;

/**
 * CalDAV calendar backend that maps webcalendar's implicit per-user calendar
 * to sabre/dav's calendar model.
 *
 * Each webcalendar user has one default calendar. Calendar object methods
 * (events/tasks/journals) are stubbed here and implemented in P4-E2.
 */
final class CoreCalendarBackend implements BackendInterface
{
    private const DEFAULT_COLOR = '#3788d8';

    /**
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function getCalendarsForUser($principalUri): array
    {
        /** @var string $uri */
        $uri = $principalUri;
        $parts = explode('/', $uri);
        $username = end($parts);

        return [
            [
                'id' => $username,
                'uri' => 'default',
                'principaluri' => $uri,
                '{DAV:}displayname' => 'Calendar',
                '{http://apple.com/ns/ical/}calendar-color' => self::DEFAULT_COLOR,
                '{' . \Sabre\CalDAV\Plugin::NS_CALDAV . '}calendar-description' => '',
                '{' . \Sabre\CalDAV\Plugin::NS_CALDAV . '}supported-calendar-component-set' =>
                    new \Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet(['VEVENT', 'VTODO', 'VJOURNAL']),
                '{' . \Sabre\CalDAV\Plugin::NS_CALDAV . '}schedule-calendar-transp' =>
                    new \Sabre\CalDAV\Xml\Property\ScheduleCalendarTransp('opaque'),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $properties
     *
     * @return string
     */
    #[\Override]
    public function createCalendar($principalUri, $calendarUri, array $properties)
    {
        // WebCalendar has one implicit calendar per user — additional calendars not supported
        /** @var string $uri */
        $uri = $principalUri;
        $parts = explode('/', $uri);

        return end($parts);
    }

    #[\Override]
    public function updateCalendar($calendarId, PropPatch $propPatch): void
    {
        // Calendar properties are not persisted in webcalendar-core
    }

    #[\Override]
    public function deleteCalendar($calendarId): void
    {
        // Cannot delete the default calendar
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function getCalendarObjects($calendarId): array
    {
        // Stub — implemented in P4-E2-S1
        return [];
    }

    /**
     * @return array<string, mixed>|null
     */
    #[\Override]
    public function getCalendarObject($calendarId, $objectUri): ?array
    {
        // Stub — implemented in P4-E2-S1
        return null;
    }

    /**
     * @param list<string> $uris
     *
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function getMultipleCalendarObjects($calendarId, array $uris): array
    {
        // Stub — implemented in P4-E2-S1
        return [];
    }

    #[\Override]
    public function createCalendarObject($calendarId, $objectUri, $calendarData): ?string
    {
        // Stub — implemented in P4-E2-S1
        return null;
    }

    #[\Override]
    public function updateCalendarObject($calendarId, $objectUri, $calendarData): ?string
    {
        // Stub — implemented in P4-E2-S1
        return null;
    }

    #[\Override]
    public function deleteCalendarObject($calendarId, $objectUri): void
    {
        // Stub — implemented in P4-E2-S1
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return list<string>
     */
    #[\Override]
    public function calendarQuery($calendarId, array $filters): array
    {
        // Stub — implemented in P4-E2-S1
        return [];
    }

    #[\Override]
    public function getCalendarObjectByUID($principalUri, $uid): ?string
    {
        // Stub — implemented in P4-E2-S1
        return null;
    }
}
