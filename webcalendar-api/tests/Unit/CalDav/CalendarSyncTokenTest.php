<?php

declare(strict_types=1);

namespace App\Tests\Unit\CalDav;

use App\CalDav\CoreCalendarBackend;
use App\Service\CalDavSyncTokenRepository;
use App\Service\CoreServiceFactory;
use App\Service\TenantAwarePdoProvider;
use PHPUnit\Framework\TestCase;
use Sabre\CalDAV\Plugin;
use Sabre\CalDAV\Xml\Property\ScheduleCalendarTransp;
use Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;
use Sabre\DAV\Exception\MethodNotAllowed;
use Symfony\Component\Clock\MockClock;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;

/**
 * Sync tokens and the change feed built on them.
 *
 * The existing CalDav unit tests all run against an empty SQLite with no
 * schema loaded, so the backend never reads a row: the token always falls
 * through to its wall-clock branch and getChangesForCalendar() always has
 * nothing to report. Both branches of "has this calendar changed" therefore
 * returned an empty list, and the comparison between them could be inverted
 * without a failure. This loads the real schema and puts events in it.
 */
final class CalendarSyncTokenTest extends TestCase
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

        $schema = file_get_contents(
            __DIR__ . '/../../../vendor/craigk5n/webcalendar-core/src/Infrastructure/Persistence/sqlite-schema.sql',
        );
        self::assertIsString($schema);

        foreach (preg_split('/;\s*\n/', (string) preg_replace('/--[^\n]*/', '', $schema)) ?: [] as $statement) {
            $statement = trim($statement);

            if ($statement !== '') {
                try {
                    $this->pdo->exec($statement);
                } catch (\PDOException) {
                    // Not every statement applies to this SQLite build.
                }
            }
        }

        $this->factory = new CoreServiceFactory($this->pdo, 'test');
        $this->alice = new User('alice', 'Alice', 'Smith', 'alice@example.com', true, true);
        $this->factory->getUserService()->createUser($this->alice, $this->alice);
    }

    private function backend(): CoreCalendarBackend
    {
        return new CoreCalendarBackend(
            new TenantAwarePdoProvider($this->pdo),
            $this->factory->getUserService(),
            $this->factory->getEventService(),
            $this->factory->getTaskService(),
            $this->factory->getJournalService(),
            $this->factory->getEventRepository(),
            $this->factory->getReminderRepository(),
            new MockClock(self::NOW),
        );
    }

    private function addEvent(string $name, string $start): void
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

    private function addShapedEvent(
        string $name,
        string $start,
        int $duration = 60,
        string $location = '',
        bool $allDay = false,
    ): void {
        $this->factory->getEventService()->createEvent(new Event(
            id: new EventId(0),
            uid: strtolower($name) . '@example.com',
            name: $name,
            description: '',
            location: $location,
            start: new \DateTimeImmutable($start),
            duration: $duration,
            createdBy: 'alice',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
            allDay: $allDay,
        ), $this->alice);
    }

    private function icsFor(string $uri): string
    {
        $object = $this->backend()->getCalendarObject('alice', $uri);
        self::assertNotNull($object);

        return (string) $object['calendardata'];
    }

    private function tokenFor(CoreCalendarBackend $backend): string
    {
        return (string) $backend->getCalendarsForUser('principals/alice')[0]['{DAV:}sync-token'];
    }

    // ------------------------------------------------------------ the token

    public function testAnEmptyCalendarFallsBackToTheClock(): void
    {
        // No events and no override leave the computed token at zero, which
        // would be a constant that never moves. Clients tolerate a token
        // jumping forward but not one that never changes, so the wall clock
        // stands in -- and with the clock injected that value is exact.
        $expected = (new \DateTimeImmutable(self::NOW))->getTimestamp();

        self::assertSame('sync-' . $expected, $this->tokenFor($this->backend()));
    }

    public function testACalendarWithEventsDerivesItsTokenFromThem(): void
    {
        $this->addEvent('One', '2026-06-01 10:00:00');

        $token = $this->tokenFor($this->backend());

        // Not the clock: the token now comes from MAX(cal_mod_date, cal_mod_time).
        self::assertNotSame('sync-' . (new \DateTimeImmutable(self::NOW))->getTimestamp(), $token);
        self::assertStringStartsWith('sync-', $token);
        self::assertGreaterThan(0, (int) substr($token, 5));
    }

    public function testABumpedOverrideWinsOverTheComputedToken(): void
    {
        // A bulk purge changes no cal_mod_date, so without the override a
        // client would never learn that anything happened.
        $this->addEvent('One', '2026-06-01 10:00:00');
        $before = $this->tokenFor($this->backend());

        (new CalDavSyncTokenRepository($this->pdo))->bumpForUsers(['alice']);
        $after = $this->tokenFor($this->backend());

        self::assertNotSame($before, $after);
        self::assertGreaterThan((int) substr($before, 5), (int) substr($after, 5));
    }

    public function testTheCtagTracksTheSyncToken(): void
    {
        $this->addEvent('One', '2026-06-01 10:00:00');
        $calendar = $this->backend()->getCalendarsForUser('principals/alice')[0];

        self::assertSame(
            $calendar['{DAV:}sync-token'],
            $calendar['{http://calendarserver.org/ns/}getctag'],
        );
    }

    // ------------------------------------------------------- the change feed

    public function testAClientHoldingTheCurrentTokenIsToldNothingChanged(): void
    {
        // The point of the comparison: two events exist, and the answer is
        // still an empty change set. Without objects in the calendar both
        // branches return empty and the comparison cannot be wrong.
        $this->addEvent('One', '2026-06-01 10:00:00');
        $this->addEvent('Two', '2026-06-02 10:00:00');
        $backend = $this->backend();

        $changes = $backend->getChangesForCalendar('alice', $this->tokenFor($backend), 1);

        self::assertSame([], $changes['added']);
        self::assertSame([], $changes['modified']);
        self::assertSame([], $changes['deleted']);
    }

    public function testAClientHoldingAStaleTokenIsSentEverything(): void
    {
        $this->addEvent('One', '2026-06-01 10:00:00');
        $this->addEvent('Two', '2026-06-02 10:00:00');
        $backend = $this->backend();

        $changes = $backend->getChangesForCalendar('alice', 'sync-1', 1);

        self::assertSame(['1.ics', '2.ics'], $changes['added']);
        self::assertSame($this->tokenFor($backend), $changes['syncToken']);
    }

    public function testAnInitialSyncIsSentEverythingToo(): void
    {
        $this->addEvent('One', '2026-06-01 10:00:00');
        $backend = $this->backend();

        $changes = $backend->getChangesForCalendar('alice', null, 1);

        self::assertSame(['1.ics'], $changes['added']);
    }

    public function testTheQueryReturnsEveryMatchingObjectNotJustTheFirst(): void
    {
        $this->addEvent('One', '2026-06-01 10:00:00');
        $this->addEvent('Two', '2026-06-02 10:00:00');
        $this->addEvent('Three', '2026-06-03 10:00:00');

        $uris = $this->backend()->calendarQuery('alice', [
            'name' => 'VCALENDAR',
            'comp-filters' => [[
                'name' => 'VEVENT',
                'comp-filters' => [],
                'prop-filters' => [],
                'is-not-defined' => false,
                'time-range' => null,
            ]],
            'prop-filters' => [],
            'is-not-defined' => false,
            'time-range' => null,
        ]);

        self::assertSame(['1.ics', '2.ics', '3.ics'], $uris);
    }

    // ------------------------------------------------- the calendar's shape

    public function testTheCalendarAdvertisesEveryComponentTypeItStores(): void
    {
        // Events, tasks and journals are all served from this one calendar.
        // A client that is not told about VTODO does not ask for tasks.
        $calendar = $this->backend()->getCalendarsForUser('principals/alice')[0];
        $set = $calendar['{' . Plugin::NS_CALDAV . '}supported-calendar-component-set'];

        self::assertInstanceOf(SupportedCalendarComponentSet::class, $set);
        self::assertSame(['VEVENT', 'VTODO', 'VJOURNAL'], $set->getValue());
    }

    public function testTheCalendarAdvertisesItsSchedulingTransparency(): void
    {
        $calendar = $this->backend()->getCalendarsForUser('principals/alice')[0];

        self::assertArrayHasKey('{' . Plugin::NS_CALDAV . '}schedule-calendar-transp', $calendar);
        self::assertInstanceOf(
            ScheduleCalendarTransp::class,
            $calendar['{' . Plugin::NS_CALDAV . '}schedule-calendar-transp'],
        );
    }

    public function testCreatingAnExtraCalendarIsRefusedWithAnExplanation(): void
    {
        // sabre's default handler would return 201 and leave a phantom
        // calendar URI behind, so the message matters as much as the throw.
        $this->expectException(MethodNotAllowed::class);
        $this->expectExceptionMessage('single default calendar per user');

        $this->backend()->createCalendar('principals/alice', 'second', []);
    }

    // ------------------------------------------------ what a client fetches

    public function testEachObjectCarriesTheMetadataAClientCachesOn(): void
    {
        // etag is what makes a conditional fetch work: if it does not change
        // with the data, a client keeps serving a stale event forever.
        $this->addEvent('One', '2026-06-01 10:00:00');

        $object = $this->backend()->getCalendarObjects('alice')[0];

        self::assertSame(1, $object['id']);
        self::assertSame('1.ics', $object['uri']);
        self::assertSame('alice', $object['calendarid']);
        self::assertSame('vevent', $object['component']);
        self::assertIsString($object['calendardata']);
        self::assertSame(\strlen((string) $object['calendardata']), $object['size']);
        self::assertSame('"' . md5((string) $object['calendardata']) . '"', $object['etag']);
        self::assertSame((new \DateTimeImmutable(self::NOW))->getTimestamp(), $object['lastmodified']);
    }

    public function testTheEtagFollowsTheData(): void
    {
        $this->addEvent('One', '2026-06-01 10:00:00');
        $this->addEvent('Two', '2026-06-02 10:00:00');

        $objects = $this->backend()->getCalendarObjects('alice');

        self::assertNotSame($objects[0]['etag'], $objects[1]['etag']);
    }

    public function testTheGeneratedIcsCarriesTheEventAClientNeeds(): void
    {
        $this->addEvent('Standup', '2026-06-01 10:00:00');

        $ics = (string) $this->backend()->getCalendarObjects('alice')[0]['calendardata'];

        self::assertStringContainsString('BEGIN:VEVENT', $ics);
        self::assertStringContainsString('UID:standup@example.com', $ics);
        self::assertStringContainsString('SUMMARY:Standup', $ics);
        self::assertStringContainsString('DTSTART', $ics);
        self::assertStringContainsString('DURATION:PT60M', $ics);
    }

    public function testAnEventWithNoLocationOmitsTheProperty(): void
    {
        $this->addEvent('Standup', '2026-06-01 10:00:00');

        self::assertStringNotContainsString(
            'LOCATION',
            (string) $this->backend()->getCalendarObjects('alice')[0]['calendardata'],
        );
    }

    public function testASingleObjectIsFetchedByItsUri(): void
    {
        $this->addEvent('One', '2026-06-01 10:00:00');
        $this->addEvent('Two', '2026-06-02 10:00:00');
        $backend = $this->backend();

        $object = $backend->getCalendarObject('alice', '2.ics');

        self::assertNotNull($object);
        self::assertSame('2.ics', $object['uri']);
        self::assertStringContainsString('SUMMARY:Two', (string) $object['calendardata']);
    }

    public function testAnUnknownUriFetchesNothing(): void
    {
        $this->addEvent('One', '2026-06-01 10:00:00');

        self::assertNull($this->backend()->getCalendarObject('alice', 'nope.ics'));
    }

    public function testSeveralObjectsAreFetchedAtOnce(): void
    {
        // The multiget path: a client asks for the uris a sync told it about,
        // and must get all of them back rather than the first.
        $this->addEvent('One', '2026-06-01 10:00:00');
        $this->addEvent('Two', '2026-06-02 10:00:00');
        $this->addEvent('Three', '2026-06-03 10:00:00');

        $objects = $this->backend()->getMultipleCalendarObjects('alice', ['1.ics', '3.ics']);

        self::assertSame(['1.ics', '3.ics'], array_column($objects, 'uri'));
    }

    public function testAnObjectIsFoundByItsUid(): void
    {
        $this->addEvent('One', '2026-06-01 10:00:00');

        self::assertSame('calendars/alice/default/1.ics', $this->backend()->getCalendarObjectByUID('principals/alice', 'one@example.com'));
    }

    // ------------------------------------------------ the shapes an ICS takes

    public function testAnAllDayEventIsWrittenAsADateNotATimestamp(): void
    {
        // VALUE=DATE is what tells a client to draw it across the day rather
        // than at midnight.
        $this->addShapedEvent('Offsite', '2026-06-01 00:00:00', allDay: true);

        $ics = $this->icsFor('1.ics');

        self::assertStringContainsString('DTSTART;VALUE=DATE:20260601', $ics);
    }

    public function testATimedEventIsWrittenWithItsTime(): void
    {
        $this->addShapedEvent('Standup', '2026-06-01 09:30:00');

        $ics = $this->icsFor('1.ics');

        self::assertStringNotContainsString('VALUE=DATE', $ics);
        self::assertStringContainsString('DTSTART', $ics);
    }

    public function testAnEventWithNoDurationOmitsTheProperty(): void
    {
        // A zero duration is not "PT0M": a client reading that would draw a
        // zero-length event rather than treating the end as unspecified.
        $this->addShapedEvent('Marker', '2026-06-01 09:00:00', duration: 0);

        self::assertStringNotContainsString('DURATION', $this->icsFor('1.ics'));
    }

    public function testALocationIsCarriedWhenThereIsOne(): void
    {
        $this->addShapedEvent('Standup', '2026-06-01 09:00:00', location: 'Room A');

        self::assertStringContainsString('LOCATION:Room A', $this->icsFor('1.ics'));
    }

    public function testAnObjectIsAlsoFetchableByAClientChosenUidFilename(): void
    {
        // Clients that PUT their own filename come back for it by that name,
        // so the numeric-id path is not the only one.
        $this->addEvent('One', '2026-06-01 10:00:00');

        $object = $this->backend()->getCalendarObject('alice', 'one@example.com.ics');

        self::assertNotNull($object);
        self::assertStringContainsString('SUMMARY:One', (string) $object['calendardata']);
    }

    public function testAnUnknownUidFetchesNothing(): void
    {
        $this->addEvent('One', '2026-06-01 10:00:00');

        self::assertNull($this->backend()->getCalendarObjectByUID('principals/alice', 'not-here@example.com'));
    }
}
