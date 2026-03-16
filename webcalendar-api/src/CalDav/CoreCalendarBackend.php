<?php

declare(strict_types=1);

namespace App\CalDav;

use App\Service\CoreServiceFactory;
use Sabre\CalDAV\Backend\BackendInterface;
use Sabre\CalDAV\Plugin;
use Sabre\CalDAV\Xml\Property\ScheduleCalendarTransp;
use Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;
use Sabre\DAV\PropPatch;
use Sabre\VObject;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;

/**
 * CalDAV calendar backend bridging webcalendar-core events to sabre/dav.
 *
 * Each user has one implicit "default" calendar. Calendar objects
 * (VEVENT) are mapped to/from webcalendar-core Event entities.
 */
final class CoreCalendarBackend implements BackendInterface
{
    private const DEFAULT_COLOR = '#3788d8';

    public function __construct(
        private readonly CoreServiceFactory $coreServiceFactory,
    ) {
    }

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
                '{' . Plugin::NS_CALDAV . '}calendar-description' => '',
                '{' . Plugin::NS_CALDAV . '}supported-calendar-component-set' =>
                    new SupportedCalendarComponentSet(['VEVENT', 'VTODO', 'VJOURNAL']),
                '{' . Plugin::NS_CALDAV . '}schedule-calendar-transp' =>
                    new ScheduleCalendarTransp('opaque'),
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
        /** @var string $uri */
        $uri = $principalUri;
        $parts = explode('/', $uri);

        return end($parts);
    }

    #[\Override]
    public function updateCalendar($calendarId, PropPatch $propPatch): void
    {
    }

    #[\Override]
    public function deleteCalendar($calendarId): void
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function getCalendarObjects($calendarId): array
    {
        /** @var string $username */
        $username = $calendarId;

        try {
            $user = $this->coreServiceFactory->getUserService()->getUserByLogin($username);
            if ($user === null) {
                return [];
            }

            $range = new DateRange(
                new \DateTimeImmutable('-2 years'),
                new \DateTimeImmutable('+2 years'),
            );
            $collection = $this->coreServiceFactory->getEventService()->getEventsInDateRange($range, $user);

            $objects = [];
            foreach ($collection->all() as $event) {
                $ics = $this->eventToIcs($event);
                $objects[] = [
                    'id' => $event->id()->value(),
                    'uri' => $event->id()->value() . '.ics',
                    'calendarid' => $username,
                    'calendardata' => $ics,
                    'lastmodified' => time(),
                    'etag' => '"' . md5($ics) . '"',
                    'size' => \strlen($ics),
                    'component' => 'vevent',
                ];
            }

            return $objects;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    #[\Override]
    public function getCalendarObject($calendarId, $objectUri): ?array
    {
        /** @var string $uri */
        $uri = $objectUri;
        $eventId = $this->extractEventId($uri);
        if ($eventId === null) {
            return null;
        }

        $event = $this->coreServiceFactory->getEventService()->getEventById(new EventId($eventId));
        if ($event === null) {
            return null;
        }

        $ics = $this->eventToIcs($event);

        return [
            'id' => $event->id()->value(),
            'uri' => $uri,
            'calendarid' => $calendarId,
            'calendardata' => $ics,
            'lastmodified' => time(),
            'etag' => '"' . md5($ics) . '"',
            'size' => \strlen($ics),
            'component' => 'vevent',
        ];
    }

    /**
     * @param list<string> $uris
     *
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function getMultipleCalendarObjects($calendarId, array $uris): array
    {
        $results = [];
        foreach ($uris as $uri) {
            $obj = $this->getCalendarObject($calendarId, $uri);
            if ($obj !== null) {
                $results[] = $obj;
            }
        }

        return $results;
    }

    #[\Override]
    public function createCalendarObject($calendarId, $objectUri, $calendarData): ?string
    {
        /** @var string $username */
        $username = $calendarId;
        /** @var string $icsString */
        $icsString = $calendarData;

        try {
            $vcalendar = VObject\Reader::read($icsString);
            if (!$vcalendar instanceof VObject\Component\VCalendar) {
                return null;
            }

            $vevent = $vcalendar->VEVENT;
            if ($vevent === null) {
                return null;
            }

            $user = $this->coreServiceFactory->getUserService()->getUserByLogin($username);
            if ($user === null) {
                return null;
            }

            $event = $this->vEventToEntity($vevent, $username);
            $this->coreServiceFactory->getEventService()->createEvent($event, $user);

            $etag = '"' . md5($icsString) . '"';

            return $etag;
        } catch (\Throwable) {
            return null;
        }
    }

    #[\Override]
    public function updateCalendarObject($calendarId, $objectUri, $calendarData): ?string
    {
        /** @var string $uri */
        $uri = $objectUri;
        /** @var string $icsString */
        $icsString = $calendarData;
        $eventId = $this->extractEventId($uri);

        if ($eventId === null) {
            return null;
        }

        try {
            $existing = $this->coreServiceFactory->getEventService()->getEventById(new EventId($eventId));
            if ($existing === null) {
                return null;
            }

            $vcalendar = VObject\Reader::read($icsString);
            if (!$vcalendar instanceof VObject\Component\VCalendar) {
                return null;
            }

            $vevent = $vcalendar->VEVENT;
            if ($vevent === null) {
                return null;
            }

            /** @var string $username */
            $username = $calendarId;
            $user = $this->coreServiceFactory->getUserService()->getUserByLogin($username);
            if ($user === null) {
                return null;
            }

            $updated = $this->vEventToEntity($vevent, $username, $eventId);
            $this->coreServiceFactory->getEventService()->updateEvent($updated, $user);

            return '"' . md5($icsString) . '"';
        } catch (\Throwable) {
            return null;
        }
    }

    #[\Override]
    public function deleteCalendarObject($calendarId, $objectUri): void
    {
        /** @var string $uri */
        $uri = $objectUri;
        $eventId = $this->extractEventId($uri);
        if ($eventId === null) {
            return;
        }

        /** @var string $username */
        $username = $calendarId;

        try {
            $user = $this->coreServiceFactory->getUserService()->getUserByLogin($username);
            if ($user === null) {
                return;
            }

            $this->coreServiceFactory->getEventService()->deleteEvent(new EventId($eventId), $user);
        } catch (\Throwable) {
            // Silently ignore delete failures
        }
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return list<string>
     */
    #[\Override]
    public function calendarQuery($calendarId, array $filters): array
    {
        $objects = $this->getCalendarObjects($calendarId);

        /** @var list<string> */
        return array_map(static fn (array $o): string => \is_string($o['uri']) ? $o['uri'] : '', $objects);
    }

    #[\Override]
    public function getCalendarObjectByUID($principalUri, $uid): ?string
    {
        /** @var string $uidStr */
        $uidStr = $uid;

        try {
            $event = $this->coreServiceFactory->getEventRepository()->findByUid($uidStr);
            if ($event === null) {
                return null;
            }

            /** @var string $pUri */
            $pUri = $principalUri;
            $parts = explode('/', $pUri);
            $username = end($parts);

            return 'calendars/' . $username . '/default/' . $event->id()->value() . '.ics';
        } catch (\Throwable) {
            return null;
        }
    }

    private function extractEventId(string $objectUri): ?int
    {
        if (!str_ends_with($objectUri, '.ics')) {
            return null;
        }

        $idStr = substr($objectUri, 0, -4);
        if (!ctype_digit($idStr)) {
            return null;
        }

        return (int) $idStr;
    }

    private function eventToIcs(Event $event): string
    {
        $vcalendar = new VObject\Component\VCalendar();
        $vevent = $vcalendar->add('VEVENT', [
            'UID' => $event->uid(),
            'SUMMARY' => $event->name(),
            'DESCRIPTION' => $event->description(),
        ]);

        $startDate = $event->start();
        if ($event->isAllDay()) {
            $vevent->add('DTSTART', $startDate->format('Ymd'), ['VALUE' => 'DATE']);
        } else {
            $vevent->add('DTSTART', $startDate);
        }

        if ($event->duration() > 0) {
            $vevent->add('DURATION', 'PT' . $event->duration() . 'M');
        }

        if ($event->location() !== '') {
            $vevent->add('LOCATION', $event->location());
        }

        return $vcalendar->serialize();
    }

    private function vEventToEntity(VObject\Component $vevent, string $createdBy, ?int $id = null): Event
    {
        $summary = (string) ($vevent->SUMMARY ?? 'Untitled');
        $description = (string) ($vevent->DESCRIPTION ?? '');
        $location = (string) ($vevent->LOCATION ?? '');
        $uid = (string) ($vevent->UID ?? 'caldav-' . bin2hex(random_bytes(8)));

        $dtstart = $vevent->DTSTART;
        $startDate = $dtstart !== null ? $dtstart->getDateTime() : new \DateTimeImmutable();
        $allDay = $dtstart !== null && isset($dtstart->parameters['VALUE']) && (string) $dtstart->parameters['VALUE'] === 'DATE';

        $duration = 0;
        if (isset($vevent->DURATION)) {
            $durationStr = (string) $vevent->DURATION;
            if (preg_match('/PT(\d+)H/', $durationStr, $hm)) {
                $duration += (int) $hm[1] * 60;
            }
            if (preg_match('/(\d+)M/', $durationStr, $mm)) {
                $duration += (int) $mm[1];
            }
        }

        $startDt = $startDate instanceof \DateTimeImmutable ? $startDate : \DateTimeImmutable::createFromMutable($startDate);

        return new Event(
            id: $id !== null ? new EventId($id) : new EventId(0),
            uid: $uid,
            name: $summary,
            description: $description,
            location: $location,
            start: $startDt,
            duration: $duration,
            createdBy: $createdBy,
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
            allDay: $allDay,
        );
    }
}
