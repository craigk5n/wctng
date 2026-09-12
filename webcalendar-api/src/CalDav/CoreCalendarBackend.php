<?php

declare(strict_types=1);

namespace App\CalDav;

use App\Service\CalDavSyncTokenRepository;
use App\Service\DescriptionSanitizer;
use App\Service\TenantAwarePdoProvider;
use App\Service\ValarmHelper;
use Psr\Clock\ClockInterface;
use Sabre\CalDAV\Backend\BackendInterface;
use Sabre\CalDAV\Backend\SchedulingSupport;
use Sabre\CalDAV\Backend\SyncSupport;
use Sabre\CalDAV\CalendarQueryValidator;
use Sabre\CalDAV\Plugin;
use Sabre\CalDAV\Xml\Property\ScheduleCalendarTransp;
use Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\MethodNotAllowed;
use Sabre\DAV\PropPatch;
use Sabre\VObject;
use Symfony\Component\Clock\NativeClock;
use WebCalendar\Core\Application\Service\EventService;
use WebCalendar\Core\Application\Service\JournalService;
use WebCalendar\Core\Application\Service\TaskService;
use WebCalendar\Core\Application\Service\UserService;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Entity\Journal;
use WebCalendar\Core\Domain\Entity\Task;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\Repository\ReminderRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventScope;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\ExDate;
use WebCalendar\Core\Domain\ValueObject\Recurrence;
use WebCalendar\Core\Domain\ValueObject\RecurrenceRule;

/**
 * CalDAV calendar backend bridging webcalendar-core events to sabre/dav.
 *
 * Each user has one implicit "default" calendar. Calendar objects
 * (VEVENT) are mapped to/from webcalendar-core Event entities.
 */
final class CoreCalendarBackend implements BackendInterface, SyncSupport, SchedulingSupport
{
    use CoreSchedulingBackend;

    private const DEFAULT_COLOR = '#3788d8';

    private readonly DescriptionSanitizer $descriptionSanitizer;
    private readonly ValarmHelper $valarmHelper;
    private ?CalDavSyncTokenRepository $syncTokenRepo = null;
    private readonly ClockInterface $clock;

    public function __construct(
        private readonly TenantAwarePdoProvider $pdoProvider,
        private readonly UserService $userService,
        private readonly EventService $eventService,
        private readonly TaskService $taskService,
        private readonly JournalService $journalService,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly ReminderRepositoryInterface $reminderRepository,
        ?ClockInterface $clock = null,
    ) {
        $this->descriptionSanitizer = new DescriptionSanitizer();
        $this->valarmHelper = new ValarmHelper();
        $this->clock = $clock ?? new NativeClock();
    }

    private function getSyncTokenRepo(): CalDavSyncTokenRepository
    {
        return $this->syncTokenRepo ??= new CalDavSyncTokenRepository($this->pdoProvider->get());
    }

    /**
     * @param string $principalUri
     *
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
                '{' . Plugin::NS_CALDAV . '}supported-calendar-component-set'
                    => new SupportedCalendarComponentSet(['VEVENT', 'VTODO', 'VJOURNAL']),
                '{' . Plugin::NS_CALDAV . '}schedule-calendar-transp'
                    => new ScheduleCalendarTransp('opaque'),
                '{DAV:}sync-token' => $this->getSyncToken($username),
                '{http://calendarserver.org/ns/}getctag' => $this->getSyncToken($username),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $properties
     *
     * @param string $principalUri
     * @param string $calendarUri
     *
     * @return string
     */
    #[\Override]
    public function createCalendar($principalUri, $calendarUri, array $properties)
    {
        // Each user gets exactly one implicit "default" calendar — we do
        // not support clients creating additional calendars. Without this
        // guard, sabre's default MKCALENDAR handler happily accepts the
        // request and returns 201, producing a phantom calendar URI that
        // does not exist in the database. Subsequent operations against
        // that URI would then fail mysteriously.
        throw new MethodNotAllowed(
            'This server supports a single default calendar per user. '
            . 'Additional calendars cannot be created via MKCALENDAR.',
        );
    }

    #[\Override]
    public function updateCalendar($calendarId, PropPatch $propPatch): void {}

    #[\Override]
    public function deleteCalendar($calendarId): void
    {
        // Calendars are not user-deletable via CalDAV. Without this guard,
        // a `DELETE /dav/calendars/{user}/default/` request would be
        // treated as success (204) and sabre's default tree walker would
        // cascade the delete through every event in the collection —
        // potentially wiping the user's entire calendar history with a
        // single HTTP request.
        throw new Forbidden('Calendars cannot be deleted via CalDAV.');
    }

    /**
     * @param mixed $calendarId
     *
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function getCalendarObjects($calendarId): array
    {
        /** @var string $username */
        $username = $calendarId;

        try {
            $user = $this->userService->getUserByLogin($username);
            if ($user === null) {
                return [];
            }

            $range = new DateRange(
                $this->clock->now()->modify('-2 years'),
                $this->clock->now()->modify('+2 years'),
            );
            $collection = $this->eventService->getEventsInDateRange($range, EventScope::forUser($user));

            $objects = [];
            foreach ($collection->all() as $event) {
                // webcal_entry holds tasks and journals alongside events, and
                // findByDateRange() does not filter on cal_type -- so without
                // this every task arrives here as well as in the VTODO loop
                // below, and the client shows it twice: once as an
                // appointment, once as a to-do. Journals the same.
                if (!\in_array($event->type(), [EventType::EVENT, EventType::REPEATING_EVENT], true)) {
                    continue;
                }

                $ics = $this->eventToIcs($event);
                $objects[] = [
                    'id' => $event->id()->value(),
                    'uri' => $event->id()->value() . '.ics',
                    'calendarid' => $username,
                    'calendardata' => $ics,
                    'lastmodified' => $this->clock->now()->getTimestamp(),
                    'etag' => '"' . md5($ics) . '"',
                    'size' => \strlen($ics),
                    'component' => 'vevent',
                ];
            }

            // Include tasks (VTODO)
            try {
                $tasks = $this->taskService->getTasksInDateRange(
                    $range,
                    EventScope::forUser($user)->limitedToUsers([$username]),
                );
                foreach ($tasks as $task) {
                    $ics = $this->taskToIcs($task);
                    $objects[] = [
                        'id' => 'task-' . $task->id()->value(),
                        'uri' => 'task-' . $task->id()->value() . '.ics',
                        'calendarid' => $username,
                        'calendardata' => $ics,
                        'lastmodified' => $this->clock->now()->getTimestamp(),
                        'etag' => '"' . md5($ics) . '"',
                        'size' => \strlen($ics),
                        'component' => 'vtodo',
                    ];
                }
            } catch (\Throwable) {
                // Tasks table may not exist
            }

            // Include journals (VJOURNAL)
            try {
                $journals = $this->journalService->getJournalsInDateRange(
                    $range,
                    EventScope::forUser($user)->limitedToUsers([$username]),
                );
                foreach ($journals as $journal) {
                    $ics = $this->journalToIcs($journal);
                    $objects[] = [
                        'id' => 'journal-' . $journal->id()->value(),
                        'uri' => 'journal-' . $journal->id()->value() . '.ics',
                        'calendarid' => $username,
                        'calendardata' => $ics,
                        'lastmodified' => $this->clock->now()->getTimestamp(),
                        'etag' => '"' . md5($ics) . '"',
                        'size' => \strlen($ics),
                        'component' => 'vjournal',
                    ];
                }
            } catch (\Throwable) {
                // Journals table may not exist
            }

            return $objects;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param mixed $calendarId
     * @param string $objectUri
     *
     * @return array<string, mixed>|null
     */
    #[\Override]
    public function getCalendarObject($calendarId, $objectUri): ?array
    {
        /** @var string $uri */
        $uri = $objectUri;

        // Handle task URIs (task-{id}.ics)
        if (str_starts_with($uri, 'task-')) {
            return $this->getTaskObject($calendarId, $uri);
        }

        // Handle journal URIs (journal-{id}.ics)
        if (str_starts_with($uri, 'journal-')) {
            return $this->getJournalObject($calendarId, $uri);
        }

        $eventId = $this->extractEventId($uri);
        if ($eventId === null) {
            return null;
        }

        $event = $this->eventService->getEventById(new EventId($eventId));
        if ($event === null) {
            return null;
        }

        $ics = $this->eventToIcs($event);

        return [
            'id' => $event->id()->value(),
            'uri' => $uri,
            'calendarid' => $calendarId,
            'calendardata' => $ics,
            'lastmodified' => $this->clock->now()->getTimestamp(),
            'etag' => '"' . md5($ics) . '"',
            'size' => \strlen($ics),
            'component' => 'vevent',
        ];
    }

    /**
     * @param list<string> $uris
     *
     * @param mixed $calendarId
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

            $user = $this->userService->getUserByLogin($username);
            if ($user === null) {
                return null;
            }

            // Handle VJOURNAL
            $vjournal = $vcalendar->VJOURNAL;
            if ($vjournal !== null) {
                $journal = $this->vJournalToEntity($vjournal, $username);
                $this->journalService->createJournal($journal, $user);
                return '"' . md5($icsString) . '"';
            }

            // Handle VTODO
            $vtodo = $vcalendar->VTODO;
            if ($vtodo !== null) {
                $task = $this->vTodoToEntity($vtodo, $username);
                $this->taskService->createTask($task, $user);
                return '"' . md5($icsString) . '"';
            }

            // Handle VEVENT
            $vevent = $vcalendar->VEVENT;
            if ($vevent === null) {
                return null;
            }

            $event = $this->vEventToEntity($vevent, $username);
            $this->eventService->createEvent($event, $user);

            // Extract and save VALARM reminders
            $this->saveValarmsForEvent($vevent, $event);

            $this->bumpSyncToken($username);

            // ETag must match what getCalendarObject() will return on a
            // subsequent read (the backend regenerates ICS from the domain
            // entity, so hashing the client's upload would give a different
            // value and break If-Match optimistic concurrency).
            $saved = $this->eventRepository->findByUid($event->uid());
            if ($saved !== null) {
                return '"' . md5($this->eventToIcs($saved)) . '"';
            }
            return '"' . md5($icsString) . '"';
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
            $existing = $this->eventService->getEventById(new EventId($eventId));
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
            $user = $this->userService->getUserByLogin($username);
            if ($user === null) {
                return null;
            }

            $updated = $this->vEventToEntity($vevent, $username, $eventId);
            $this->eventService->updateEvent($updated, $user);

            $this->bumpSyncToken($username);

            // Return the ETag computed from the regenerated ICS so it
            // matches what a subsequent GET sees (see createCalendarObject
            // for the rationale).
            $saved = $this->eventService->getEventById(new EventId($eventId));
            if ($saved !== null) {
                return '"' . md5($this->eventToIcs($saved)) . '"';
            }
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
            $user = $this->userService->getUserByLogin($username);
            if ($user === null) {
                return;
            }

            $this->eventService->deleteEvent(new EventId($eventId), $user);
            $this->bumpSyncToken($username);
        } catch (\Throwable) {
            // Silently ignore delete failures
        }
    }

    /**
     * Bumps the user's CalDAV sync-token override so clients notice the
     * change on their next sync. This is the same mechanism PurgeService
     * uses (see CalDavSyncTokenRepository / DEL-S1).
     *
     * We write the override on every mutation because the vendor
     * repository does not populate webcal_entry.cal_mod_date/cal_mod_time,
     * which means the computed `MAX(cal_mod_date * 1e6 + cal_mod_time)`
     * token never advances on create/update/delete — without an override,
     * CalDAV clients would observe a stale token and skip a pull cycle.
     */
    private function bumpSyncToken(string $username): void
    {
        try {
            $this->getSyncTokenRepo()->bumpForUsers([$username]);
        } catch (\Throwable) {
            // Best-effort — never block a successful mutation on a sync bump.
        }
    }

    /**
     * Full CalDAV filter evaluation via sabre's CalendarQueryValidator.
     *
     * The previous implementation only filtered by component-type and
     * returned every matching URI without applying time-range, prop-filter,
     * or text-match — which meant a client asking "events in April 2026"
     * received the user's entire event history. This mirrors the default
     * AbstractBackend::calendarQuery logic: iterate every object, parse
     * its VCALENDAR body, and ask the validator whether the filter matches.
     *
     * @param array<string, mixed> $filters
     *
     * @param mixed $calendarId
     *
     * @return list<string>
     */
    #[\Override]
    public function calendarQuery($calendarId, array $filters): array
    {
        $validator = new CalendarQueryValidator();
        $result = [];

        foreach ($this->getCalendarObjects($calendarId) as $object) {
            /** @var string|null $uri */
            $uri = \is_string($object['uri'] ?? null) ? $object['uri'] : null;
            if ($uri === null) {
                continue;
            }

            $calendarData = $object['calendardata'] ?? null;
            if (!\is_string($calendarData) || $calendarData === '') {
                // Re-fetch the full object if the listing omitted
                // calendardata (backend implementations often do this to
                // keep getCalendarObjects cheap).
                $full = $this->getCalendarObject($calendarId, $uri);
                if ($full === null || !\is_string($full['calendardata'] ?? null)) {
                    continue;
                }
                $calendarData = $full['calendardata'];
            }

            try {
                $vObject = VObject\Reader::read($calendarData);
                if (!$vObject instanceof VObject\Component\VCalendar) {
                    continue;
                }

                if ($validator->validate($vObject, $filters)) {
                    $result[] = $uri;
                }

                // Destroy circular references so PHP can GC the VObject tree.
                $vObject->destroy();
            } catch (\Throwable) {
                // Malformed calendardata — skip this object rather than
                // failing the whole query.
                continue;
            }
        }

        return $result;
    }

    #[\Override]
    public function getCalendarObjectByUID($principalUri, $uid): ?string
    {
        /** @var string $uidStr */
        $uidStr = $uid;

        try {
            $event = $this->eventRepository->findByUid($uidStr);
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

    /**
     * @param string $calendarId
     * @param string $syncToken
     * @param int $syncLevel
     * @param int|null $limit
     *
     * @return array{syncToken: string, added: list<string>, modified: list<string>, deleted: list<string>}
     */
    #[\Override]
    public function getChangesForCalendar($calendarId, $syncToken, $syncLevel, $limit = null): array
    {
        // Since webcalendar-core doesn't have change tracking, return all objects
        // as "added" when no sync token is provided, or empty changes when token matches.
        /** @var string $currentToken */
        $currentToken = $this->getSyncToken((string) $calendarId);

        if ($syncToken === $currentToken) {
            return [
                'syncToken' => $currentToken,
                'added' => [],
                'modified' => [],
                'deleted' => [],
            ];
        }

        // Token mismatch or initial sync — return all objects as added
        $objects = $this->getCalendarObjects($calendarId);
        $added = array_map(static fn(array $o): string => \is_string($o['uri']) ? $o['uri'] : '', $objects);

        return [
            'syncToken' => $currentToken,
            'added' => $added,
            'modified' => [],
            'deleted' => [],
        ];
    }

    private function getSyncToken(string $username): string
    {
        $computed = 0;
        try {
            $pdo = $this->pdoProvider->get();
            $stmt = $pdo->prepare('SELECT MAX(cal_mod_date * 1000000 + COALESCE(cal_mod_time, 0)) AS max_mod FROM webcal_entry WHERE cal_create_by = :user');
            $stmt->execute(['user' => $username]);
            $val = $stmt->fetchColumn();
            $computed = \is_numeric($val) ? (int) $val : 0;
        } catch (\Throwable) {
            // Schema missing or query failed — fall through and let the
            // override (if any) still take effect.
        }

        // Bulk ops (admin purge) bump an override so clients see a
        // forward-moving token even when no cal_mod_date changed.
        $override = 0;
        try {
            $override = $this->getSyncTokenRepo()->getOverride($username) ?? 0;
        } catch (\Throwable) {
            // Override table missing — fall through.
        }

        $token = max($computed, $override);
        if ($token === 0) {
            // No events, no override — use wall clock so the token is
            // still monotonic across restarts (clients tolerate a jump
            // but not a constant zero).
            $token = $this->clock->now()->getTimestamp();
        }
        return 'sync-' . $token;
    }

    /**
     * Resolves a client-provided CalDAV object URI (e.g. "abc-123.ics") to
     * an internal event id.
     *
     * Two forms are supported, in priority order:
     *
     *   1. Numeric filename (`42.ics`) — treated as the event's primary key.
     *      This is the form we synthesise when listing objects, so clients
     *      that remember the server-assigned URI will hit this path.
     *
     *   2. UID filename (`abc-123-def.ics`) — matched against
     *      `webcal_entry.cal_uid`. Every real CalDAV client (Apple Calendar,
     *      Thunderbird, DAVx5, iCloud, Fastmail) uses the iCalendar UID as
     *      the resource filename per RFC 4791 §5.3.2, so without this path
     *      interop is broken: events can be created via PUT but not fetched
     *      back, updated, or deleted at the client's chosen URI.
     */
    private function extractEventId(string $objectUri): ?int
    {
        if (!str_ends_with($objectUri, '.ics')) {
            return null;
        }

        $key = substr($objectUri, 0, -4);
        if ($key === '') {
            return null;
        }

        if (ctype_digit($key)) {
            return (int) $key;
        }

        // Fall back to UID lookup for client-chosen filenames.
        try {
            $event = $this->eventRepository->findByUid($key);
            if ($event !== null) {
                return $event->id()->value();
            }
        } catch (\Throwable) {
            // fall through
        }

        return null;
    }

    /**
     * The event's last revision as a UTC stamp, for DTSTAMP, or null when the
     * row carries no usable one.
     *
     * cal_mod_date and cal_mod_time are written on every save, in the PHP
     * default timezone, so they are read back the same way before being
     * converted. Only an entity that was never saved has neither, and an ETag
     * is only ever hashed from one that was.
     */
    private static function revisionStamp(Event $event): ?\DateTimeImmutable
    {
        $modDate = $event->modDate();
        $modTime = $event->modTime();

        // A legacy row can hold 0 rather than a date, which would otherwise be
        // published as DTSTAMP:-00011130T000000Z. Half a stamp is no stamp
        // either: a date with no time cannot say when the event was revised.
        if ($modDate === null || $modTime === null || $modDate < 19700101) {
            return null;
        }

        $stamp = \DateTimeImmutable::createFromFormat(
            'YmdHis',
            \sprintf('%08d%06d', $modDate, $modTime),
        );

        return $stamp === false ? null : $stamp->setTimezone(new \DateTimeZone('UTC'));
    }

    private function eventToIcs(Event $event): string
    {
        $properties = [
            'UID' => $event->uid(),
            'SUMMARY' => $event->name(),
            'DESCRIPTION' => $event->description(),
        ];

        // VObject stamps DTSTAMP with the current second when it is not given
        // one, which made this serialisation -- and so every ETag hashed from
        // it -- change once a second for an event nobody had touched. Clients
        // then re-fetched everything on each sync, and If-Match updates failed
        // whenever the two requests fell either side of a second. RFC 5545
        // wants the last revision time here for an object carrying no METHOD,
        // which is exactly what the row records.
        $revision = self::revisionStamp($event);
        if ($revision !== null) {
            $properties['DTSTAMP'] = $revision;
        }

        $vcalendar = new VObject\Component\VCalendar();
        $vevent = $vcalendar->add('VEVENT', $properties);

        // Component::add() is declared `@return Node`, and Node has no add().
        // Adding a component by name always yields a Component at runtime, so
        // narrow here rather than further down: every $vevent->add() below then
        // type-checks instead of being suppressed.
        if (!$vevent instanceof VObject\Component) {
            return $vcalendar->serialize();
        }

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

        // Recurrence
        $recurrence = $event->recurrence();
        if ($recurrence->rule() !== null) {
            $vevent->add('RRULE', $recurrence->rule()->toString());
        }

        if (!$recurrence->exDate()->isEmpty()) {
            foreach ($recurrence->exDate()->dates() as $exDate) {
                // webcal_entry_repeats_not stores a bare date, and the
                // repository rebuilds it with createFromFormat('Ymd', ...),
                // which fills the time from the current clock. Emitting that
                // verbatim gives an EXDATE whose time is whenever the sync
                // happened to run, and RFC 5545 requires an EXDATE to match
                // the occurrence's DTSTART exactly -- so it cancels nothing
                // and the occurrence the user deleted comes back. Every
                // occurrence of a series starts at the series' own time, so
                // that is the time to pair the stored date with. An all-day
                // series has a DATE-valued DTSTART, and RFC 5545 requires
                // EXDATE to use the same value type, so that case emits a
                // bare date rather than a midnight timestamp.
                if ($event->isAllDay()) {
                    $vevent->add('EXDATE', $exDate->format('Ymd'), ['VALUE' => 'DATE']);

                    continue;
                }

                $occurrence = new \DateTimeImmutable(
                    $exDate->format('Y-m-d') . ' ' . $startDate->format('H:i:s'),
                    $startDate->getTimezone(),
                );
                $vevent->add('EXDATE', $occurrence->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z'));
            }
        }

        // Add VALARM from reminders
        try {
            $reminderRepo = $this->reminderRepository;
            $pending = $reminderRepo->findPending();
            foreach ($pending as $entry) {
                if ($entry['reminder']->eventId() === $event->id()->value()) {
                    $this->valarmHelper->addValarmToComponent($vevent, $entry['reminder']);
                }
            }
        } catch (\Throwable) {
            // Reminders table may not exist yet
        }

        return $vcalendar->serialize();
    }

    private function vEventToEntity(VObject\Component $vevent, string $createdBy, ?int $id = null): Event
    {
        $summary = (string) ($vevent->SUMMARY ?? 'Untitled');
        $description = $this->extractDescription($vevent);
        $location = (string) ($vevent->LOCATION ?? '');
        $uid = (string) ($vevent->UID ?? 'caldav-' . bin2hex(random_bytes(8)));

        $dtstart = $vevent->DTSTART;
        $startDate = $dtstart !== null ? $dtstart->getDateTime() : $this->clock->now();
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

        // Parse recurrence
        $rrule = null;
        $exDates = [];

        if (isset($vevent->RRULE)) {
            try {
                $rrule = new RecurrenceRule((string) $vevent->RRULE);
            } catch (\InvalidArgumentException) {
                // Skip invalid RRULE
            }
        }

        if (isset($vevent->EXDATE)) {
            foreach ($vevent->select('EXDATE') as $exDateProp) {
                /** @var VObject\Property\ICalendar\DateTime $exDateProp */
                $exDates[] = $exDateProp->getDateTime();
            }
        }

        $recurrence = new Recurrence(
            rule: $rrule,
            exDate: new ExDate($exDates),
        );

        $eventType = $rrule !== null ? EventType::REPEATING_EVENT : EventType::EVENT;

        return new Event(
            id: $id !== null ? new EventId($id) : new EventId(0),
            uid: $uid,
            name: $summary,
            description: $description,
            location: $location,
            start: $startDt,
            duration: $duration,
            createdBy: $createdBy,
            type: $eventType,
            access: AccessLevel::PUBLIC,
            recurrence: $recurrence,
            allDay: $allDay,
        );
    }

    private function taskToIcs(Task $task): string
    {
        $vcalendar = new VObject\Component\VCalendar();
        $vtodo = $vcalendar->add('VTODO', [
            'UID' => $task->uid(),
            'SUMMARY' => $task->name(),
            'DESCRIPTION' => $task->description(),
            'PERCENT-COMPLETE' => (string) $task->percentComplete(),
        ]);

        // Component::add() is declared `@return Node`, and Node has no add().
        // Adding a component by name always yields a Component at runtime, so
        // narrow here rather than further down: every $vtodo->add() below then
        // type-checks instead of being suppressed.
        if (!$vtodo instanceof VObject\Component) {
            return $vcalendar->serialize();
        }

        if ($task->dueDate() !== null) {
            $vtodo->add('DUE', $task->dueDate());
        }

        $vtodo->add('DTSTART', $task->start());

        if ($task->percentComplete() >= 100) {
            $vtodo->add('STATUS', 'COMPLETED');
        } elseif ($task->percentComplete() > 0) {
            $vtodo->add('STATUS', 'IN-PROCESS');
        } else {
            $vtodo->add('STATUS', 'NEEDS-ACTION');
        }

        return $vcalendar->serialize();
    }

    private function vTodoToEntity(VObject\Component $vtodo, string $createdBy, ?int $id = null): Task
    {
        $summary = (string) ($vtodo->SUMMARY ?? 'Untitled');
        $description = $this->extractDescription($vtodo);
        $percentComplete = 0;

        if (isset($vtodo->{'PERCENT-COMPLETE'})) {
            $percentComplete = (int) (string) $vtodo->{'PERCENT-COMPLETE'};
        }

        $startDate = $this->clock->now();
        if (isset($vtodo->DTSTART)) {
            $dt = $vtodo->DTSTART->getDateTime();
            $startDate = $dt instanceof \DateTimeImmutable ? $dt : \DateTimeImmutable::createFromMutable($dt);
        }

        $dueDate = null;
        if (isset($vtodo->DUE)) {
            $dt = $vtodo->DUE->getDateTime();
            $dueDate = $dt instanceof \DateTimeImmutable ? $dt : \DateTimeImmutable::createFromMutable($dt);
        }

        $uid = (string) ($vtodo->UID ?? 'caldav-task-' . bin2hex(random_bytes(8)));

        return new Task(
            id: $id !== null ? new EventId($id) : new EventId(0),
            uid: $uid,
            name: $summary,
            description: $description,
            location: '',
            start: $startDate,
            duration: 0,
            createdBy: $createdBy,
            type: EventType::TASK,
            access: AccessLevel::PUBLIC,
            dueDate: $dueDate,
            percentComplete: $percentComplete,
        );
    }

    /**
     * @param mixed $calendarId
     *
     * @return array<string, mixed>|null
     */
    private function getTaskObject(mixed $calendarId, string $uri): ?array
    {
        $idPart = substr($uri, 5); // Remove 'task-' prefix
        if (!str_ends_with($idPart, '.ics')) {
            return null;
        }
        $taskIdStr = substr($idPart, 0, -4);
        if (!ctype_digit($taskIdStr)) {
            return null;
        }

        try {
            $taskId = (int) $taskIdStr;
            $task = $this->taskService->getTaskById(new EventId($taskId));
            if ($task === null) {
                return null;
            }

            $ics = $this->taskToIcs($task);

            return [
                'id' => 'task-' . $task->id()->value(),
                'uri' => $uri,
                'calendarid' => $calendarId,
                'calendardata' => $ics,
                'lastmodified' => $this->clock->now()->getTimestamp(),
                'etag' => '"' . md5($ics) . '"',
                'size' => \strlen($ics),
                'component' => 'vtodo',
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private function journalToIcs(Journal $journal): string
    {
        $vcalendar = new VObject\Component\VCalendar();
        $vcalendar->add('VJOURNAL', [
            'UID' => $journal->uid(),
            'SUMMARY' => $journal->name(),
            'DESCRIPTION' => $journal->description(),
            'DTSTART' => $journal->start()->format('Ymd'),
        ]);

        return $vcalendar->serialize();
    }

    private function vJournalToEntity(VObject\Component $vjournal, string $createdBy, ?int $id = null): Journal
    {
        $summary = (string) ($vjournal->SUMMARY ?? 'Untitled');
        $description = $this->extractDescription($vjournal);
        $uid = (string) ($vjournal->UID ?? 'caldav-journal-' . bin2hex(random_bytes(8)));

        $startDate = $this->clock->now();
        if (isset($vjournal->DTSTART)) {
            $dt = $vjournal->DTSTART->getDateTime();
            $startDate = $dt instanceof \DateTimeImmutable ? $dt : \DateTimeImmutable::createFromMutable($dt);
        }

        return new Journal(
            id: $id !== null ? new EventId($id) : new EventId(0),
            uid: $uid,
            name: $summary,
            description: $description,
            location: '',
            start: $startDate,
            duration: 0,
            createdBy: $createdBy,
            type: EventType::JOURNAL,
            access: AccessLevel::PUBLIC,
        );
    }

    /**
     * @param mixed $calendarId
     *
     * @return array<string, mixed>|null
     */
    private function getJournalObject(mixed $calendarId, string $uri): ?array
    {
        $idPart = substr($uri, 8); // Remove 'journal-' prefix
        if (!str_ends_with($idPart, '.ics')) {
            return null;
        }
        $journalIdStr = substr($idPart, 0, -4);
        if (!ctype_digit($journalIdStr)) {
            return null;
        }

        try {
            $journalId = (int) $journalIdStr;
            $journal = $this->journalService->getJournalById(new EventId($journalId));
            if ($journal === null) {
                return null;
            }

            $ics = $this->journalToIcs($journal);

            return [
                'id' => 'journal-' . $journal->id()->value(),
                'uri' => $uri,
                'calendarid' => $calendarId,
                'calendardata' => $ics,
                'lastmodified' => $this->clock->now()->getTimestamp(),
                'etag' => '"' . md5($ics) . '"',
                'size' => \strlen($ics),
                'component' => 'vjournal',
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Extracts VALARM components and saves them as reminders.
     */
    private function saveValarmsForEvent(VObject\Component $component, Event $event): void
    {
        try {
            $reminders = $this->valarmHelper->extractReminders($component, $event->id()->value());
            if (\count($reminders) === 0) {
                return;
            }

            // Find the created event by UID to get the real ID
            $created = $this->eventRepository->findByUid($event->uid());
            if ($created === null) {
                return;
            }

            $reminderRepo = $this->reminderRepository;
            // Save first reminder (table supports one per event)
            $reminder = $reminders[0];
            $reminderRepo->save(new \WebCalendar\Core\Domain\Entity\Reminder(
                eventId: $created->id()->value(),
                offset: $reminder->offset(),
                related: $reminder->related(),
                before: $reminder->before(),
                action: $reminder->action(),
            ));
        } catch (\Throwable) {
            // Reminders table may not exist
        }
    }

    /**
     * Extracts description from a VEVENT/VTODO/VJOURNAL with priority:
     * 1. STYLED-DESCRIPTION (RFC 9073)
     * 2. X-ALT-DESC with FMTTYPE=text/html (Outlook/Thunderbird)
     * 3. DESCRIPTION (plain text fallback)
     *
     * HTML descriptions are sanitized before storage.
     */
    private function extractDescription(VObject\Component $component): string
    {
        // Priority 1: STYLED-DESCRIPTION (RFC 9073)
        /** @var VObject\Property|null $styled */
        $styled = $component->{'STYLED-DESCRIPTION'} ?? null;
        if ($styled !== null) {
            return $this->descriptionSanitizer->sanitize((string) $styled);
        }

        // Priority 2: X-ALT-DESC with HTML content type
        /** @var VObject\Property|null $altDesc */
        $altDesc = $component->{'X-ALT-DESC'} ?? null;
        if ($altDesc instanceof VObject\Property) {
            $params = $altDesc->parameters();
            $fmttype = '';
            foreach ($params as $param) {
                if (strtoupper($param->name) === 'FMTTYPE') {
                    $fmttype = (string) $param->getValue();
                    break;
                }
            }
            if (stripos($fmttype, 'text/html') !== false) {
                return $this->descriptionSanitizer->sanitize((string) $altDesc);
            }
        }

        // Priority 3: DESCRIPTION (plain text)
        return (string) ($component->DESCRIPTION ?? '');
    }
}
