<?php

declare(strict_types=1);

namespace App\Tests\Unit\CalDav;

use App\CalDav\CoreCalendarBackend;
use App\Service\CoreServiceFactory;
use App\Service\TenantAwarePdoProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The scheduling inbox/outbox store.
 *
 * CoreSchedulingBackend is a trait with no test of its own -- it was only ever
 * reached incidentally through CoreCalendarBackend, and nothing called any of
 * its four methods. It holds the iTIP objects a CalDAV client posts for
 * meeting invitations and free/busy replies, so losing one, handing back the
 * wrong one, or wiping a principal's queue are all silent failures of
 * scheduling rather than visible errors.
 *
 * Nothing here touches the database: the store is in memory by design, because
 * webcalendar-core has no persistent scheduling queue.
 */
final class SchedulingObjectStoreTest extends TestCase
{
    private const NOW = '2026-06-15T12:00:00+00:00';
    private const ALICE = 'principals/alice';
    private const BOB = 'principals/bob';

    private CoreCalendarBackend $backend;

    #[\Override]
    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $factory = new CoreServiceFactory($pdo, 'test');

        $this->backend = new CoreCalendarBackend(
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

    private static function invite(string $summary): string
    {
        return "BEGIN:VCALENDAR\r\nMETHOD:REQUEST\r\nBEGIN:VEVENT\r\nSUMMARY:{$summary}\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    /** @return list<string> */
    private function urisFor(string $principal): array
    {
        return array_map(
            static fn(array $o): string => (string) $o['uri'],
            $this->backend->getSchedulingObjects($principal),
        );
    }

    // --------------------------------------------------------------- storing

    public function testAStoredObjectComesBackWithEverythingSabreReadsOffIt(): void
    {
        $data = self::invite('Quarterly review');
        $this->backend->createSchedulingObject(self::ALICE, 'invite-1.ics', $data);

        $object = $this->backend->getSchedulingObject(self::ALICE, 'invite-1.ics');

        self::assertNotNull($object);
        self::assertSame('invite-1.ics', $object['uri']);
        self::assertSame($data, $object['calendardata']);
        self::assertSame('"' . md5($data) . '"', $object['etag'], 'etags are quoted per RFC 7232');
        self::assertSame(\strlen($data), $object['size']);
        self::assertSame((new \DateTimeImmutable(self::NOW))->getTimestamp(), $object['lastmodified']);
    }

    public function testAnEtagChangesWithTheBody(): void
    {
        $this->backend->createSchedulingObject(self::ALICE, 'a.ics', self::invite('One'));
        $this->backend->createSchedulingObject(self::ALICE, 'b.ics', self::invite('Two'));

        $first = $this->backend->getSchedulingObject(self::ALICE, 'a.ics');
        $second = $this->backend->getSchedulingObject(self::ALICE, 'b.ics');

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertNotSame($first['etag'], $second['etag']);
    }

    public function testASecondObjectDoesNotDisplaceTheFirst(): void
    {
        // The queue is initialised only when the principal has none. Invert
        // that test and every new object resets the principal's queue, so an
        // inbox never holds more than the invitation that arrived last.
        $this->backend->createSchedulingObject(self::ALICE, 'first.ics', self::invite('First'));
        $this->backend->createSchedulingObject(self::ALICE, 'second.ics', self::invite('Second'));

        self::assertSame(['first.ics', 'second.ics'], $this->urisFor(self::ALICE));
    }

    public function testOnePrincipalsInboxIsNotAnothers(): void
    {
        $this->backend->createSchedulingObject(self::ALICE, 'for-alice.ics', self::invite('Alice'));
        $this->backend->createSchedulingObject(self::BOB, 'for-bob.ics', self::invite('Bob'));

        self::assertSame(['for-alice.ics'], $this->urisFor(self::ALICE));
        self::assertSame(['for-bob.ics'], $this->urisFor(self::BOB));
        self::assertNull($this->backend->getSchedulingObject(self::ALICE, 'for-bob.ics'));
    }

    // -------------------------------------------------------------- fetching

    public function testTheObjectFetchedIsTheOneAskedFor(): void
    {
        // The lookup compares uris. Invert that comparison and it returns the
        // first object that is *not* the one requested -- which for an inbox
        // with one other invitation in it is a plausible-looking reply to the
        // wrong meeting.
        $this->backend->createSchedulingObject(self::ALICE, 'a.ics', self::invite('Alpha'));
        $this->backend->createSchedulingObject(self::ALICE, 'b.ics', self::invite('Beta'));

        $object = $this->backend->getSchedulingObject(self::ALICE, 'b.ics');

        self::assertNotNull($object);
        self::assertSame('b.ics', $object['uri']);
        self::assertStringContainsString('SUMMARY:Beta', (string) $object['calendardata']);
    }

    public function testAnUnknownUriHasNoObject(): void
    {
        $this->backend->createSchedulingObject(self::ALICE, 'a.ics', self::invite('Alpha'));

        self::assertNull($this->backend->getSchedulingObject(self::ALICE, 'nothing-here.ics'));
    }

    public function testAPrincipalWithNoInboxListsNothing(): void
    {
        self::assertSame([], $this->backend->getSchedulingObjects(self::ALICE));
        self::assertNull($this->backend->getSchedulingObject(self::ALICE, 'a.ics'));
    }

    // -------------------------------------------------------------- deleting

    public function testDeletingFromTheMiddleLeavesAListNotAGappedArray(): void
    {
        // Sabre serialises this straight out; an array with a hole in its keys
        // encodes as a JSON object rather than a list, so the reindex is what
        // keeps the inbox looking like a collection.
        foreach (['a.ics', 'b.ics', 'c.ics'] as $uri) {
            $this->backend->createSchedulingObject(self::ALICE, $uri, self::invite($uri));
        }

        $this->backend->deleteSchedulingObject(self::ALICE, 'b.ics');

        $remaining = $this->backend->getSchedulingObjects(self::ALICE);
        self::assertSame(['a.ics', 'c.ics'], $this->urisFor(self::ALICE));
        self::assertSame([0, 1], array_keys($remaining));
    }

    public function testDeletingOneLeavesTheOthersAlone(): void
    {
        $this->backend->createSchedulingObject(self::ALICE, 'keep.ics', self::invite('Keep'));
        $this->backend->createSchedulingObject(self::ALICE, 'drop.ics', self::invite('Drop'));

        $this->backend->deleteSchedulingObject(self::ALICE, 'drop.ics');

        self::assertSame(['keep.ics'], $this->urisFor(self::ALICE));
        self::assertNotNull($this->backend->getSchedulingObject(self::ALICE, 'keep.ics'));
        self::assertNull($this->backend->getSchedulingObject(self::ALICE, 'drop.ics'));
    }

    public function testDeletingFromAPrincipalWithNoInboxIsHarmless(): void
    {
        $this->backend->deleteSchedulingObject(self::ALICE, 'never-existed.ics');

        self::assertSame([], $this->backend->getSchedulingObjects(self::ALICE));
    }

    public function testDeletingDoesNotTouchAnotherPrincipalsInbox(): void
    {
        $this->backend->createSchedulingObject(self::ALICE, 'shared-name.ics', self::invite('Alice'));
        $this->backend->createSchedulingObject(self::BOB, 'shared-name.ics', self::invite('Bob'));

        $this->backend->deleteSchedulingObject(self::ALICE, 'shared-name.ics');

        self::assertSame([], $this->urisFor(self::ALICE));
        self::assertSame(['shared-name.ics'], $this->urisFor(self::BOB));
    }
}
