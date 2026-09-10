<?php

declare(strict_types=1);

namespace App\Tests\Unit\CalDav;

use App\CalDav\CoreCalendarBackend;
use App\Service\CoreServiceFactory;
use App\Service\TenantAwarePdoProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Entity\Journal;
use WebCalendar\Core\Domain\Entity\Reminder;
use WebCalendar\Core\Domain\Entity\Task;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;

/**
 * What getCalendarObjects() and getCalendarObject() actually hand a client.
 *
 * The existing CalDav unit tests for tasks and journals build ICS strings by
 * hand and parse them back with Sabre, which asserts that Sabre works rather
 * than that this backend emits anything. The task and journal branches of the
 * listing were therefore never entered: emptying either loop, or dropping the
 * fields sabre reads out of each row, changed nothing any test could see.
 */
final class CalendarObjectListingTest extends TestCase
{
    private const string NOW = '2026-06-15T12:00:00+00:00';

    private \PDO $pdo;
    private CoreServiceFactory $factory;
    private User $alice;

    #[\Override]
    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->loadSchema($this->pdo);

        $this->factory = new CoreServiceFactory($this->pdo, 'test');
        $this->alice = new User('alice', 'Alice', 'Smith', 'alice@example.com', true, true);
        $this->factory->getUserService()->createUser($this->alice, $this->alice);
    }

    private function loadSchema(\PDO $pdo): void
    {
        $schema = file_get_contents(
            __DIR__ . '/../../../vendor/craigk5n/webcalendar-core/src/Infrastructure/Persistence/sqlite-schema.sql',
        );
        self::assertIsString($schema);

        foreach (preg_split('/;\s*\n/', (string) preg_replace('/--[^\n]*/', '', $schema)) ?: [] as $statement) {
            $statement = trim($statement);

            if ($statement !== '') {
                try {
                    $pdo->exec($statement);
                } catch (\PDOException) {
                    // Not every statement applies to this SQLite build.
                }
            }
        }
    }

    private function backend(?\PDO $pdo = null): CoreCalendarBackend
    {
        $pdo ??= $this->pdo;
        $factory = $pdo === $this->pdo ? $this->factory : new CoreServiceFactory($pdo, 'test');

        return new CoreCalendarBackend(
            new TenantAwarePdoProvider($pdo),
            $factory->getUserService(),
            $factory->getEventService(),
            $factory->getTaskService(),
            $factory->getJournalService(),
            $factory->getEventRepository(),
            $factory->getReminderRepository(),
            new MockClock(self::NOW),
        );
    }

    private function addEvent(string $name = 'Standup', string $start = '2026-07-01T09:00:00+00:00'): void
    {
        $this->factory->getEventService()->createEvent(new Event(
            id: new EventId(0),
            uid: strtolower($name) . '@example.com',
            name: $name,
            description: '',
            location: '',
            start: new \DateTimeImmutable($start),
            duration: 60,
            createdBy: 'alice',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
        ), $this->alice);
    }

    private function addTask(string $name = 'File taxes', int $percentComplete = 50): void
    {
        $this->factory->getTaskService()->createTask(new Task(
            id: new EventId(0),
            uid: 'task-' . strtolower(str_replace(' ', '-', $name)) . '@example.com',
            name: $name,
            description: 'before the deadline',
            location: '',
            start: new \DateTimeImmutable('2026-07-02T09:00:00+00:00'),
            duration: 0,
            createdBy: 'alice',
            type: EventType::TASK,
            access: AccessLevel::PUBLIC,
            dueDate: new \DateTimeImmutable('2026-07-10T17:00:00+00:00'),
            percentComplete: $percentComplete,
        ), $this->alice);
    }

    private function addJournal(string $name = 'Retro notes'): void
    {
        $this->factory->getJournalService()->createJournal(new Journal(
            id: new EventId(0),
            uid: 'journal-' . strtolower(str_replace(' ', '-', $name)) . '@example.com',
            name: $name,
            description: 'what went well',
            location: '',
            start: new \DateTimeImmutable('2026-07-03T09:00:00+00:00'),
            duration: 0,
            createdBy: 'alice',
            type: EventType::JOURNAL,
            access: AccessLevel::PUBLIC,
        ), $this->alice);
    }

    /** @return list<array<string, mixed>> */
    private function listing(): array
    {
        return $this->backend()->getCalendarObjects('alice');
    }

    /** @param list<array<string, mixed>> $objects */
    private static function componentsOf(array $objects): array
    {
        return array_map(static fn(array $o): mixed => $o['component'], $objects);
    }

    public function testATaskIsListedAsItsOwnVtodoObject(): void
    {
        // The task loop was never entered: no test put a task in the
        // database, so emptying it left every assertion still true.
        $this->addTask();

        $objects = $this->listing();

        self::assertSame(['vtodo'], self::componentsOf($objects));
        self::assertStringContainsString('BEGIN:VTODO', (string) $objects[0]['calendardata']);
        self::assertStringContainsString('SUMMARY:File taxes', (string) $objects[0]['calendardata']);
    }

    public function testAJournalIsListedAsItsOwnVjournalObject(): void
    {
        $this->addJournal();

        $objects = $this->listing();

        self::assertSame(['vjournal'], self::componentsOf($objects));
        self::assertStringContainsString('BEGIN:VJOURNAL', (string) $objects[0]['calendardata']);
        self::assertStringContainsString('SUMMARY:Retro notes', (string) $objects[0]['calendardata']);
    }

    public function testEventsTasksAndJournalsShareOneListing(): void
    {
        // A client asks for the collection once and expects all three
        // component types back; dropping either loop silently hides a whole
        // category of the user's data from every CalDAV client.
        $this->addEvent();
        $this->addTask();
        $this->addJournal();

        self::assertSame(['vevent', 'vtodo', 'vjournal'], self::componentsOf($this->listing()));
    }

    public function testATaskUriIsNamespacedSoItCannotCollideWithAnEvent(): void
    {
        // Tasks and events have separate id sequences, so "1.ics" would be
        // ambiguous. getCalendarObject() routes on the prefix.
        $this->addEvent();
        $this->addTask();

        $uris = array_map(static fn(array $o): mixed => $o['uri'], $this->listing());

        self::assertCount(2, $uris);
        self::assertMatchesRegularExpression('/^\d+\.ics$/', (string) $uris[0]);
        self::assertMatchesRegularExpression('/^task-\d+\.ics$/', (string) $uris[1]);
    }

    public function testATaskIsNotAlsoListedAsAnAppointment(): void
    {
        // webcal_entry holds all three component types and the repository's
        // date-range query does not filter on cal_type, so the event loop
        // sees tasks and journals too. Without the type guard each one is
        // emitted twice -- as N.ics (VEVENT) and as task-N.ics (VTODO) --
        // and a CalDAV client shows the same item in both its calendar and
        // its to-do list.
        $this->addTask();
        $this->addJournal();

        $objects = $this->listing();

        self::assertSame(['vtodo', 'vjournal'], self::componentsOf($objects));
    }

    // ------------------------------------------- the fields sabre reads back

    public function testAListedObjectCarriesEverythingSabreNeedsToSyncIt(): void
    {
        $this->addEvent();

        $object = $this->listing()[0];
        $body = (string) $object['calendardata'];

        self::assertSame('alice', $object['calendarid']);
        self::assertSame('"' . md5($body) . '"', $object['etag'], 'etags are quoted per RFC 7232');
        self::assertSame(\strlen($body), $object['size']);
        self::assertSame((new \DateTimeImmutable(self::NOW))->getTimestamp(), $object['lastmodified']);
        self::assertSame($object['id'] . '.ics', $object['uri']);
    }

    public function testFetchingOneObjectAgreesWithTheListing(): void
    {
        // Sabre lists the collection and then fetches individual members;
        // a field that differs between the two paths shows up as a client
        // re-downloading an object it already has, or missing a change.
        $this->addEvent();

        $listed = $this->listing()[0];
        $fetched = $this->backend()->getCalendarObject('alice', (string) $listed['uri']);

        self::assertNotNull($fetched);
        self::assertSame($listed, $fetched);
    }

    public function testAnEtagChangesWhenTheBodyDoes(): void
    {
        // The etag is the quoted md5 of the body. Dropping either quote or
        // the hash itself still yields a stable-looking string, so assert
        // the relationship rather than the format alone.
        $this->addEvent('Standup');
        $first = $this->backend()->getCalendarObjects('alice')[0];

        $this->addEvent('Retro', '2026-07-04T09:00:00+00:00');
        $objects = $this->backend()->getCalendarObjects('alice');

        self::assertNotSame($first['etag'], $objects[1]['etag']);
        self::assertSame('"' . md5((string) $objects[1]['calendardata']) . '"', $objects[1]['etag']);
    }

    // -------------------------------------------------- lookups that miss

    public function testAnUnknownUidHasNoObject(): void
    {
        $this->addEvent();

        self::assertNull($this->backend()->getCalendarObjectByUID('principals/alice', 'nobody@example.com'));
    }

    public function testAUriThatNamesNoEventHasNoObject(): void
    {
        $this->addEvent();

        self::assertNull($this->backend()->getCalendarObject('alice', '99999.ics'));
    }

    public function testABodyThatIsNotACalendarIsRejected(): void
    {
        // Sabre hands the raw request body straight through. A VCARD, or an
        // ICS fragment with no VCALENDAR wrapper, parses without throwing --
        // so the instanceof check is the only thing between it and being
        // treated as calendar data.
        $vcard = "BEGIN:VCARD\r\nVERSION:4.0\r\nFN:Alice\r\nEND:VCARD\r\n";

        self::assertNull($this->backend()->createCalendarObject('alice', 'card.ics', $vcard));
    }

    public function testMkcalendarExplainsWhyItRefused(): void
    {
        // The message is what a user sees in their client when adding a
        // calendar fails; an empty or truncated one leaves them guessing.
        $this->expectException(\Sabre\DAV\Exception\MethodNotAllowed::class);
        $this->expectExceptionMessage(
            'This server supports a single default calendar per user. '
            . 'Additional calendars cannot be created via MKCALENDAR.',
        );

        $this->backend()->createCalendar('principals/alice', 'work', []);
    }

    // ------------------------------------------------------ alarms and dates

    public function testAPendingReminderBecomesAValarmOnItsOwnEvent(): void
    {
        $this->addEvent();
        $id = (int) $this->listing()[0]['id'];
        $this->factory->getReminderRepository()->save(new Reminder(
            eventId: $id,
            offset: 15,
            related: 'S',
            before: 'Y',
            lastSent: 0,
        ));

        $ics = (string) $this->backend()->getCalendarObject('alice', $id . '.ics')['calendardata'];

        self::assertStringContainsString('BEGIN:VALARM', $ics);
        self::assertStringContainsString('TRIGGER', $ics);
    }

    public function testAReminderOnOneEventDoesNotAlarmAnother(): void
    {
        // findPending() returns every unsent reminder in the database, not
        // the ones for this event -- the loop filters by event id. Without
        // that filter one person's reminder attaches to everybody's events.
        $this->addEvent('Standup');
        $this->addEvent('Retro', '2026-07-04T09:00:00+00:00');
        $objects = $this->listing();
        $this->factory->getReminderRepository()->save(new Reminder(
            eventId: (int) $objects[0]['id'],
            offset: 15,
            lastSent: 0,
        ));

        $other = (string) $this->backend()->getCalendarObject('alice', $objects[1]['id'] . '.ics')['calendardata'];

        self::assertStringNotContainsString('BEGIN:VALARM', $other);
    }

    // ----------------------------------------------------- the sync token

    public function testASyncTokenSurvivesAStringifyingDriverAndAZeroModDate(): void
    {
        // Two things combine here. MySQL's PDO returns every column as a
        // string by default, and a row carried over from a legacy calendar
        // can have cal_mod_date 0. Without the int cast the computed token
        // is the *string* "0", which is not identical to the int 0, so the
        // wall-clock fallback below it never fires -- and every client is
        // handed the constant "sync-0", a token that never moves and so a
        // calendar that never appears to change.
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, true);
        $this->loadSchema($pdo);

        $factory = new CoreServiceFactory($pdo, 'test');
        $factory->getUserService()->createUser($this->alice, $this->alice);
        $factory->getEventService()->createEvent(new Event(
            id: new EventId(0),
            uid: 'legacy@example.com',
            name: 'Imported',
            description: '',
            location: '',
            start: new \DateTimeImmutable('2026-07-01T09:00:00+00:00'),
            duration: 60,
            createdBy: 'alice',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
        ), $this->alice);
        $pdo->exec('UPDATE webcal_entry SET cal_mod_date = 0, cal_mod_time = 0');

        $token = $this->backend($pdo)->getCalendarsForUser('principals/alice')[0]['{DAV:}sync-token'];

        self::assertNotSame('sync-0', $token, 'a token of zero never moves');
        self::assertSame(
            'sync-' . (new \DateTimeImmutable(self::NOW))->getTimestamp(),
            $token,
            'it falls back to the clock instead',
        );
    }

    // --------------------------------------------------- excluded dates

    public function testAnExcludedOccurrenceIsWrittenBackOut(): void
    {
        // A client cancels one occurrence of a series by adding EXDATE. If
        // the guard around that loop is inverted the exception is dropped on
        // the way back out, and the occurrence the user deleted reappears at
        // the next sync.
        $ics = "BEGIN:VCALENDAR\r\n"
            . "VERSION:2.0\r\n"
            . "BEGIN:VEVENT\r\n"
            . "UID:series@example.com\r\n"
            . "DTSTART:20260701T090000Z\r\n"
            . "DURATION:PT1H\r\n"
            . "SUMMARY:Weekly sync\r\n"
            . "RRULE:FREQ=WEEKLY;COUNT=5\r\n"
            . "EXDATE:20260715T090000Z\r\n"
            . "END:VEVENT\r\n"
            . "END:VCALENDAR\r\n";

        $this->backend()->createCalendarObject('alice', 'series.ics', $ics);
        $stored = $this->backend()->getCalendarObjectByUID('principals/alice', 'series@example.com');
        self::assertNotNull($stored);
        $body = (string) $this->backend()->getCalendarObject('alice', basename($stored))['calendardata'];

        self::assertStringContainsString('EXDATE:20260715T090000Z', $body);
    }

    public function testAnAllDaySeriesExcludesADateNotAMidnightTimestamp(): void
    {
        // RFC 5545 3.8.5.1: EXDATE must use the same value type as DTSTART.
        // An all-day series has DTSTART;VALUE=DATE, so a DATE-TIME exception
        // does not identify any of its occurrences and clients drop it.
        $ics = "BEGIN:VCALENDAR\r\n"
            . "VERSION:2.0\r\n"
            . "BEGIN:VEVENT\r\n"
            . "UID:allday@example.com\r\n"
            . "DTSTART;VALUE=DATE:20260701\r\n"
            . "SUMMARY:Office closed\r\n"
            . "RRULE:FREQ=WEEKLY;COUNT=5\r\n"
            . "EXDATE;VALUE=DATE:20260715\r\n"
            . "END:VEVENT\r\n"
            . "END:VCALENDAR\r\n";

        $this->backend()->createCalendarObject('alice', 'allday.ics', $ics);
        $stored = $this->backend()->getCalendarObjectByUID('principals/alice', 'allday@example.com');
        self::assertNotNull($stored);
        $body = (string) $this->backend()->getCalendarObject('alice', basename($stored))['calendardata'];

        self::assertStringContainsString('DTSTART;VALUE=DATE:20260701', $body);
        self::assertStringContainsString('EXDATE;VALUE=DATE:20260715', $body);
    }

    public function testAnExcludedOccurrenceKeepsItsTimeAcrossRepeatedReads(): void
    {
        // The stored exception is a bare date; the time has to come from the
        // series. If it comes from the clock instead, two reads of the same
        // unchanged event disagree -- which is also why the etag churns.
        $ics = "BEGIN:VCALENDAR\r\n"
            . "VERSION:2.0\r\n"
            . "BEGIN:VEVENT\r\n"
            . "UID:stable@example.com\r\n"
            . "DTSTART:20260701T143000Z\r\n"
            . "DURATION:PT1H\r\n"
            . "SUMMARY:Afternoon sync\r\n"
            . "RRULE:FREQ=WEEKLY;COUNT=5\r\n"
            . "EXDATE:20260715T143000Z\r\n"
            . "END:VEVENT\r\n"
            . "END:VCALENDAR\r\n";

        $this->backend()->createCalendarObject('alice', 'stable.ics', $ics);
        $stored = $this->backend()->getCalendarObjectByUID('principals/alice', 'stable@example.com');
        self::assertNotNull($stored);
        $uri = basename($stored);

        $body = (string) $this->backend()->getCalendarObject('alice', $uri)['calendardata'];

        self::assertStringContainsString('EXDATE:20260715T143000Z', $body, 'the exception keeps the series time');
    }

    // ------------------------------------ tasks and journals as sync members

    public function testATaskCarriesTheSameSyncFieldsAnEventDoes(): void
    {
        $this->addTask();

        $object = $this->listing()[0];
        $body = (string) $object['calendardata'];

        self::assertSame('alice', $object['calendarid']);
        self::assertSame('"' . md5($body) . '"', $object['etag']);
        self::assertSame(\strlen($body), $object['size']);
        self::assertSame((new \DateTimeImmutable(self::NOW))->getTimestamp(), $object['lastmodified']);
        self::assertSame($object['id'] . '.ics', $object['uri']);
        self::assertSame('task-1', $object['id'], 'the id carries the component prefix too');
    }

    public function testAJournalCarriesTheSameSyncFieldsAnEventDoes(): void
    {
        $this->addJournal();

        $object = $this->listing()[0];
        $body = (string) $object['calendardata'];

        self::assertSame('alice', $object['calendarid']);
        self::assertSame('"' . md5($body) . '"', $object['etag']);
        self::assertSame(\strlen($body), $object['size']);
        self::assertSame((new \DateTimeImmutable(self::NOW))->getTimestamp(), $object['lastmodified']);
        self::assertSame($object['id'] . '.ics', $object['uri']);
        self::assertSame('journal-1', $object['id'], 'the id carries the component prefix too');
    }

    public function testATaskUriRoutesBackToTheVtodoBuilder(): void
    {
        // Events and tasks share the webcal_entry id sequence, so the prefix
        // is not there to disambiguate the number -- it is what tells
        // getCalendarObject() which builder to fetch the object with. Strip
        // it and the task comes back rendered as an appointment.
        $this->addEvent();
        $this->addTask();

        $taskUri = (string) $this->listing()[1]['uri'];
        $fetched = $this->backend()->getCalendarObject('alice', $taskUri);

        self::assertNotNull($fetched);
        self::assertSame('vtodo', $fetched['component']);
        self::assertStringContainsString('BEGIN:VTODO', (string) $fetched['calendardata']);
    }

    public function testAnEventAfterATaskIsStillListed(): void
    {
        // The type guard skips a row; it must not stop the loop. With a
        // break there instead, every event that sorts after the user's
        // first task disappears from the collection.
        $this->addEvent('Early', '2026-07-01T09:00:00+00:00');
        $this->addTask();
        $this->addEvent('Late', '2026-07-05T09:00:00+00:00');

        $summaries = array_map(
            static fn(array $o): string => (string) $o['calendardata'],
            $this->listing(),
        );

        self::assertCount(3, $summaries);
        self::assertStringContainsString('SUMMARY:Late', implode('', $summaries));
    }

    // ----------------------------------------------------- the VTODO body

    /** @return iterable<string, array{int, string}> */
    public static function taskCompletionStates(): iterable
    {
        // The two thresholds are the only thing distinguishing three states,
        // and a client renders each of them differently.
        yield 'untouched' => [0, 'NEEDS-ACTION'];
        yield 'just started' => [1, 'IN-PROCESS'];
        yield 'half way' => [50, 'IN-PROCESS'];
        yield 'nearly there' => [99, 'IN-PROCESS'];
        yield 'done' => [100, 'COMPLETED'];
    }

    #[DataProvider('taskCompletionStates')]
    public function testPercentCompleteDecidesTheTaskStatus(int $percent, string $expected): void
    {
        $this->addTask('Paint fence', $percent);

        $body = (string) $this->listing()[0]['calendardata'];

        self::assertStringContainsString('STATUS:' . $expected, $body);
        self::assertStringContainsString('PERCENT-COMPLETE:' . $percent, $body);
    }

    public function testATaskCarriesItsDueDateAndStart(): void
    {
        // DUE is what a client sorts and alerts on; without it the task
        // shows up as undated no matter what the user entered.
        $this->addTask();

        $body = (string) $this->listing()[0]['calendardata'];

        self::assertStringContainsString('DUE:20260710T170000Z', $body);
        self::assertStringContainsString('DTSTART:20260702T090000Z', $body);
        self::assertStringContainsString('DESCRIPTION:before the deadline', $body);
    }

    public function testAJournalCarriesItsDateAndText(): void
    {
        $this->addJournal();

        $body = (string) $this->listing()[0]['calendardata'];

        self::assertStringContainsString('UID:journal-retro-notes@example.com', $body);
        self::assertStringContainsString('SUMMARY:Retro notes', $body);
        self::assertStringContainsString('DESCRIPTION:what went well', $body);
        self::assertStringContainsString('DTSTART:20260703', $body);
    }

    // ------------------------------------------------- what a client uploads

    private static function upload(
        string $uid = 'up@example.com',
        string $extra = '',
        string $summaryLine = "SUMMARY:Uploaded\r\n",
    ): string {
        return "BEGIN:VCALENDAR\r\n"
            . "VERSION:2.0\r\n"
            . "BEGIN:VEVENT\r\n"
            . ($uid === '' ? '' : "UID:{$uid}\r\n")
            . "DTSTART:20260701T090000Z\r\n"
            . $summaryLine
            . $extra
            . "END:VEVENT\r\n"
            . "END:VCALENDAR\r\n";
    }

    private function bodyForUid(string $uid): string
    {
        $stored = $this->backend()->getCalendarObjectByUID('principals/alice', $uid);
        self::assertNotNull($stored);
        $object = $this->backend()->getCalendarObject('alice', basename($stored));
        self::assertNotNull($object);

        return (string) $object['calendardata'];
    }

    public function testTheEtagFromAnUploadMatchesTheNextRead(): void
    {
        // The backend regenerates the ICS from the stored entity, so hashing
        // the client's upload would give an etag that never matches what the
        // next GET returns -- and every If-Match request would 412.
        $returned = $this->backend()->createCalendarObject('alice', 'up.ics', self::upload());

        $stored = $this->backend()->getCalendarObjectByUID('principals/alice', 'up@example.com');
        self::assertNotNull($stored);
        $fetched = $this->backend()->getCalendarObject('alice', basename($stored));

        self::assertNotNull($fetched);
        self::assertSame($fetched['etag'], $returned);
    }

    public function testAnUploadMovesTheSyncToken(): void
    {
        // Clients poll the collection token to decide whether to re-sync.
        // A token that does not move after a write means the change is
        // never picked up by anyone else.
        $before = $this->backend()->getCalendarsForUser('principals/alice')[0]['{DAV:}sync-token'];

        $this->backend()->createCalendarObject('alice', 'up.ics', self::upload());

        self::assertNotSame(
            $before,
            $this->backend()->getCalendarsForUser('principals/alice')[0]['{DAV:}sync-token'],
        );
    }

    public function testAnUploadedAlarmSurvivesTheRoundTrip(): void
    {
        $alarm = "BEGIN:VALARM\r\nACTION:DISPLAY\r\nTRIGGER:-PT30M\r\nDESCRIPTION:Soon\r\nEND:VALARM\r\n";

        $this->backend()->createCalendarObject('alice', 'up.ics', self::upload(extra: $alarm));

        self::assertStringContainsString('BEGIN:VALARM', $this->bodyForUid('up@example.com'));
    }

    public function testAnEventWithNoSummaryGetsAPlaceholder(): void
    {
        // Sabre will happily accept a VEVENT with no SUMMARY; the entity
        // needs a name, and an empty one renders as a blank row.
        $this->backend()->createCalendarObject('alice', 'up.ics', self::upload(summaryLine: ''));

        self::assertStringContainsString('SUMMARY:Untitled', $this->bodyForUid('up@example.com'));
    }

    public function testAnEventWithNoUidGetsOneGenerated(): void
    {
        // Without a UID the object cannot be addressed by any client, and
        // the next upload of the same event would create a duplicate.
        $this->backend()->createCalendarObject('alice', 'up.ics', self::upload(uid: ''));

        $objects = $this->listing();
        self::assertCount(1, $objects);
        self::assertMatchesRegularExpression(
            '/UID:caldav-[0-9a-f]{16}/',
            (string) $objects[0]['calendardata'],
        );
    }

    public function testALocationIsKept(): void
    {
        $this->backend()->createCalendarObject(
            'alice',
            'up.ics',
            self::upload(extra: "LOCATION:Room 3\r\n"),
        );

        self::assertStringContainsString('LOCATION:Room 3', $this->bodyForUid('up@example.com'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function uploadedDurations(): iterable
    {
        yield 'minutes only' => ['PT45M', 'PT45M'];
        yield 'whole hours' => ['PT2H', 'PT120M'];
        yield 'hours and minutes' => ['PT2H30M', 'PT150M'];
    }

    #[DataProvider('uploadedDurations')]
    public function testAnUploadedDurationIsStoredInMinutes(string $sent, string $expected): void
    {
        // The parser adds the hour and minute components separately; losing
        // either one silently shortens every meeting a client uploads.
        $this->backend()->createCalendarObject(
            'alice',
            'up.ics',
            self::upload(extra: "DURATION:{$sent}\r\n"),
        );

        self::assertStringContainsString('DURATION:' . $expected, $this->bodyForUid('up@example.com'));
    }

    public function testAnUploadedRecurrenceMakesItARepeatingEvent(): void
    {
        $this->backend()->createCalendarObject(
            'alice',
            'up.ics',
            self::upload(extra: "RRULE:FREQ=DAILY;COUNT=3\r\n"),
        );

        self::assertStringContainsString('RRULE:FREQ=DAILY;COUNT=3', $this->bodyForUid('up@example.com'));
    }

    public function testAnUploadedSummaryIsKept(): void
    {
        // The placeholder is a fallback, not a replacement: a client that
        // sends a title must get it back.
        $this->backend()->createCalendarObject('alice', 'up.ics', self::upload());

        self::assertStringContainsString('SUMMARY:Uploaded', $this->bodyForUid('up@example.com'));
    }

    public function testAnUpdateWithABodyThatIsNotACalendarIsRejected(): void
    {
        // Same guard as the create path, and the same reason: a VCARD parses
        // cleanly, so only the instanceof check stops it overwriting an event.
        $this->backend()->createCalendarObject('alice', 'up.ics', self::upload());
        $stored = $this->backend()->getCalendarObjectByUID('principals/alice', 'up@example.com');
        self::assertNotNull($stored);

        $result = $this->backend()->updateCalendarObject(
            'alice',
            basename($stored),
            "BEGIN:VCARD\r\nVERSION:4.0\r\nFN:Alice\r\nEND:VCARD\r\n",
        );

        self::assertNull($result);
        self::assertStringContainsString('SUMMARY:Uploaded', $this->bodyForUid('up@example.com'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function uploadsAndTheirStoredType(): iterable
    {
        // cal_type is what the repository's date-range query uses to decide
        // whether to look for recurrence rows at all, so a repeating event
        // filed as a plain one stops expanding.
        yield 'with a rule' => ["RRULE:FREQ=DAILY;COUNT=3\r\n", 'M'];
        yield 'without one' => ['', 'E'];
    }

    #[DataProvider('uploadsAndTheirStoredType')]
    public function testARecurrenceRuleDecidesTheStoredEntryType(string $extra, string $expected): void
    {
        $this->backend()->createCalendarObject('alice', 'up.ics', self::upload(extra: $extra));

        self::assertSame(
            $expected,
            $this->pdo->query('SELECT cal_type FROM webcal_entry')?->fetchColumn(),
        );
    }

    public function testATaskBodyCarriesItsUid(): void
    {
        // Without a UID the client cannot match the VTODO to anything it
        // already has, so every sync looks like a brand new task.
        $this->addTask();

        self::assertStringContainsString(
            'UID:task-file-taxes@example.com',
            (string) $this->listing()[0]['calendardata'],
        );
    }

    public function testFetchingATaskAgreesWithTheListing(): void
    {
        $this->addTask();

        $listed = $this->listing()[0];
        $fetched = $this->backend()->getCalendarObject('alice', (string) $listed['uri']);

        self::assertNotNull($fetched);
        self::assertSame($listed, $fetched);
    }

    public function testFetchingAJournalAgreesWithTheListing(): void
    {
        $this->addJournal();

        $listed = $this->listing()[0];
        $fetched = $this->backend()->getCalendarObject('alice', (string) $listed['uri']);

        self::assertNotNull($fetched);
        self::assertSame($listed, $fetched);
    }

    public function testAnAllDaySeriesCanExcludeMoreThanOneDate(): void
    {
        // The all-day branch skips to the next exception; a break there
        // would keep only the first one, quietly resurrecting every other
        // occurrence the user cancelled.
        $ics = "BEGIN:VCALENDAR\r\n"
            . "VERSION:2.0\r\n"
            . "BEGIN:VEVENT\r\n"
            . "UID:twoout@example.com\r\n"
            . "DTSTART;VALUE=DATE:20260701\r\n"
            . "SUMMARY:Office closed\r\n"
            . "RRULE:FREQ=WEEKLY;COUNT=6\r\n"
            . "EXDATE;VALUE=DATE:20260715\r\n"
            . "EXDATE;VALUE=DATE:20260722\r\n"
            . "END:VEVENT\r\n"
            . "END:VCALENDAR\r\n";

        $this->backend()->createCalendarObject('alice', 'twoout.ics', $ics);
        $body = $this->bodyForUid('twoout@example.com');

        self::assertStringContainsString('EXDATE;VALUE=DATE:20260715', $body);
        self::assertStringContainsString('EXDATE;VALUE=DATE:20260722', $body);
    }

    public function testAStyledDescriptionWinsOverThePlainOne(): void
    {
        // RFC 9073 STYLED-DESCRIPTION carries the formatted body; the plain
        // DESCRIPTION beside it is the degraded copy clients fall back to.
        // Preferring the plain one throws the formatting away on every sync.
        $extra = "DESCRIPTION:plain copy\r\n"
            . "STYLED-DESCRIPTION;VALUE=TEXT;FMTTYPE=text/html:<p>rich copy</p>\r\n";

        $this->backend()->createCalendarObject('alice', 'up.ics', self::upload(extra: $extra));
        $body = $this->bodyForUid('up@example.com');

        self::assertStringContainsString('rich copy', $body);
        self::assertStringNotContainsString('plain copy', $body);
    }

    // ------------------------------------------------- editing what is there

    private function storedUriFor(string $uid): string
    {
        $stored = $this->backend()->getCalendarObjectByUID('principals/alice', $uid);
        self::assertNotNull($stored);

        return basename($stored);
    }

    public function testAnUpdateReplacesTheStoredEvent(): void
    {
        // Nothing here exercised a successful update, so every guard on the
        // way in could be inverted and the suite still passed: each one just
        // returned null, and no test looked at what the event became.
        $this->backend()->createCalendarObject('alice', 'up.ics', self::upload());
        $uri = $this->storedUriFor('up@example.com');

        $etag = $this->backend()->updateCalendarObject(
            'alice',
            $uri,
            self::upload(summaryLine: "SUMMARY:Renamed\r\n", extra: "LOCATION:Room 9\r\n"),
        );

        self::assertNotNull($etag);
        $body = $this->bodyForUid('up@example.com');
        self::assertStringContainsString('SUMMARY:Renamed', $body);
        self::assertStringContainsString('LOCATION:Room 9', $body);
        self::assertCount(1, $this->listing(), 'an update edits in place rather than adding');
    }

    public function testTheEtagFromAnUpdateMatchesTheNextRead(): void
    {
        $this->backend()->createCalendarObject('alice', 'up.ics', self::upload());
        $uri = $this->storedUriFor('up@example.com');

        $etag = $this->backend()->updateCalendarObject(
            'alice',
            $uri,
            self::upload(summaryLine: "SUMMARY:Renamed\r\n"),
        );

        $fetched = $this->backend()->getCalendarObject('alice', $uri);
        self::assertNotNull($fetched);
        self::assertSame($fetched['etag'], $etag);
    }

    public function testUpdatingAUriThatNamesNothingIsRejected(): void
    {
        $this->backend()->createCalendarObject('alice', 'up.ics', self::upload());

        self::assertNull($this->backend()->updateCalendarObject('alice', '99999.ics', self::upload()));
        self::assertStringContainsString('SUMMARY:Uploaded', $this->bodyForUid('up@example.com'));
    }

    public function testAnUpdateMovesTheSyncToken(): void
    {
        $this->backend()->createCalendarObject('alice', 'up.ics', self::upload());
        $uri = $this->storedUriFor('up@example.com');
        $before = $this->backend()->getCalendarsForUser('principals/alice')[0]['{DAV:}sync-token'];

        $this->backend()->updateCalendarObject(
            'alice',
            $uri,
            self::upload(summaryLine: "SUMMARY:Renamed\r\n", extra: "DURATION:PT90M\r\n"),
        );

        self::assertNotSame(
            $before,
            $this->backend()->getCalendarsForUser('principals/alice')[0]['{DAV:}sync-token'],
        );
    }
}
