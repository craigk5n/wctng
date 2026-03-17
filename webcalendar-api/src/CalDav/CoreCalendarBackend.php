<?php

declare(strict_types=1);

namespace App\CalDav;

use App\Service\CoreServiceFactory;
use Sabre\CalDAV\Backend\BackendInterface;
use Sabre\CalDAV\Backend\SchedulingSupport;
use Sabre\CalDAV\Backend\SyncSupport;
use Sabre\CalDAV\Plugin;
use Sabre\CalDAV\Xml\Property\ScheduleCalendarTransp;
use Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;
use Sabre\DAV\PropPatch;
use Sabre\VObject;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Entity\Journal;
use WebCalendar\Core\Domain\Entity\Task;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventId;
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
                '{DAV:}sync-token' => $this->getSyncToken($username),
                '{http://calendarserver.org/ns/}getctag' => $this->getSyncToken($username),
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

            // Include tasks (VTODO)
            try {
                $tasks = $this->coreServiceFactory->getTaskService()->getTasksInDateRange($range, $username);
                foreach ($tasks as $task) {
                    $ics = $this->taskToIcs($task);
                    $objects[] = [
                        'id' => 'task-' . $task->id()->value(),
                        'uri' => 'task-' . $task->id()->value() . '.ics',
                        'calendarid' => $username,
                        'calendardata' => $ics,
                        'lastmodified' => time(),
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
                $journals = $this->coreServiceFactory->getJournalService()->getJournalsInDateRange($range, $username);
                foreach ($journals as $journal) {
                    $ics = $this->journalToIcs($journal);
                    $objects[] = [
                        'id' => 'journal-' . $journal->id()->value(),
                        'uri' => 'journal-' . $journal->id()->value() . '.ics',
                        'calendarid' => $username,
                        'calendardata' => $ics,
                        'lastmodified' => time(),
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

            $user = $this->coreServiceFactory->getUserService()->getUserByLogin($username);
            if ($user === null) {
                return null;
            }

            // Handle VJOURNAL
            $vjournal = $vcalendar->VJOURNAL;
            if ($vjournal !== null) {
                $journal = $this->vJournalToEntity($vjournal, $username);
                $this->coreServiceFactory->getJournalService()->createJournal($journal, $user);
                return '"' . md5($icsString) . '"';
            }

            // Handle VTODO
            $vtodo = $vcalendar->VTODO;
            if ($vtodo !== null) {
                $task = $this->vTodoToEntity($vtodo, $username);
                $this->coreServiceFactory->getTaskService()->createTask($task, $user);
                return '"' . md5($icsString) . '"';
            }

            // Handle VEVENT
            $vevent = $vcalendar->VEVENT;
            if ($vevent === null) {
                return null;
            }

            $event = $this->vEventToEntity($vevent, $username);
            $this->coreServiceFactory->getEventService()->createEvent($event, $user);

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

    /**
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
        $added = array_map(static fn (array $o): string => \is_string($o['uri']) ? $o['uri'] : '', $objects);

        return [
            'syncToken' => $currentToken,
            'added' => $added,
            'modified' => [],
            'deleted' => [],
        ];
    }

    private function getSyncToken(string $username): string
    {
        try {
            $pdo = $this->coreServiceFactory->getPdo();
            $stmt = $pdo->prepare('SELECT MAX(cal_mod_date * 1000000 + COALESCE(cal_mod_time, 0)) AS max_mod FROM webcal_entry WHERE cal_create_by = :user');
            $stmt->execute(['user' => $username]);
            $val = $stmt->fetchColumn();

            $token = \is_numeric($val) ? (string) $val : '0';

            return 'sync-' . $token;
        } catch (\Throwable) {
            return 'sync-' . time();
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

        // Recurrence
        $recurrence = $event->recurrence();
        if ($recurrence->rule() !== null) {
            $vevent->add('RRULE', $recurrence->rule()->toString());
        }

        if (!$recurrence->exDate()->isEmpty()) {
            foreach ($recurrence->exDate()->dates() as $exDate) {
                $vevent->add('EXDATE', $exDate->format('Ymd\THis\Z'));
            }
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
        $description = (string) ($vtodo->DESCRIPTION ?? '');
        $percentComplete = 0;

        if (isset($vtodo->{'PERCENT-COMPLETE'})) {
            $percentComplete = (int) (string) $vtodo->{'PERCENT-COMPLETE'};
        }

        $startDate = new \DateTimeImmutable();
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
            $task = $this->coreServiceFactory->getTaskService()->getTaskById(new EventId($taskId));
            if ($task === null) {
                return null;
            }

            $ics = $this->taskToIcs($task);

            return [
                'id' => 'task-' . $task->id()->value(),
                'uri' => $uri,
                'calendarid' => $calendarId,
                'calendardata' => $ics,
                'lastmodified' => time(),
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
        $description = (string) ($vjournal->DESCRIPTION ?? '');
        $uid = (string) ($vjournal->UID ?? 'caldav-journal-' . bin2hex(random_bytes(8)));

        $startDate = new \DateTimeImmutable();
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
            $journal = $this->coreServiceFactory->getJournalService()->getJournalById(new EventId($journalId));
            if ($journal === null) {
                return null;
            }

            $ics = $this->journalToIcs($journal);

            return [
                'id' => 'journal-' . $journal->id()->value(),
                'uri' => $uri,
                'calendarid' => $calendarId,
                'calendardata' => $ics,
                'lastmodified' => time(),
                'etag' => '"' . md5($ics) . '"',
                'size' => \strlen($ics),
                'component' => 'vjournal',
            ];
        } catch (\Throwable) {
            return null;
        }
    }
}
