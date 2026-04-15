<?php

declare(strict_types=1);

namespace App\Tests\Integration\CalDav;

use App\Tests\Integration\IntegrationTestCase;

/**
 * Protocol-level CalDAV coverage: MKCALENDAR rejection, HEAD requests,
 * time-range filter correctness, multi-resource listing, calendar-schedule
 * capability advertisement, collection-level DELETE protection, and
 * PROPFIND on a nonexistent principal.
 */
final class CalDavProtocolTest extends IntegrationTestCase
{
    private CalDavServerHarness $harness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->harness = new CalDavServerHarness($this->factory, 'admin');
    }

    private function sampleEvent(string $uid, string $summary, string $dtstart): string
    {
        return "BEGIN:VCALENDAR\r\n"
            . "VERSION:2.0\r\n"
            . "PRODID:-//test//EN\r\n"
            . "BEGIN:VEVENT\r\n"
            . "UID:{$uid}\r\n"
            . "DTSTAMP:20260601T080000Z\r\n"
            . "DTSTART:{$dtstart}\r\n"
            . 'DTEND:' . substr($dtstart, 0, 9) . "100000Z\r\n"
            . "SUMMARY:{$summary}\r\n"
            . "END:VEVENT\r\n"
            . "END:VCALENDAR\r\n";
    }

    // -- HEAD ----------------------------------------------------------------

    public function testHeadReturnsHeadersWithoutBody(): void
    {
        $uid = 'head-' . bin2hex(random_bytes(4));
        $uri = "/dav/calendars/admin/default/{$uid}.ics";
        $this->harness->putIcs($uri, $this->sampleEvent($uid, 'HEAD Test', '20260601T090000Z'));

        $response = $this->harness->invoke('HEAD', $uri);

        $this->assertSame(200, $response->getStatus(), 'HEAD on existing resource should be 200');
        $this->assertSame('', $response->getBodyAsString(), 'HEAD response must have an empty body');
        // Content-Type should still be advertised on HEAD responses
        $contentType = $response->getHeader('Content-Type') ?? '';
        $this->assertStringContainsString('text/calendar', $contentType);
    }

    public function testHeadOnNonexistentReturns404(): void
    {
        $response = $this->harness->invoke('HEAD', '/dav/calendars/admin/default/no-such-event.ics');
        $this->assertSame(404, $response->getStatus());
    }

    // -- MKCALENDAR ----------------------------------------------------------

    public function testMkcalendarOnNewUriIsRejected(): void
    {
        // Our backend exposes a single "default" calendar per user and
        // rejects attempts to create additional ones. createCalendar
        // throws MethodNotAllowed (405) so clients get a clean RFC 2616
        // response rather than a phantom calendar that doesn't exist in
        // the database.
        $body = <<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <c:mkcalendar xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
              <d:set>
                <d:prop>
                  <d:displayname>Second Calendar</d:displayname>
                </d:prop>
              </d:set>
            </c:mkcalendar>
            XML;

        $response = $this->harness->invoke(
            'MKCALENDAR',
            '/dav/calendars/admin/my-new-calendar/',
            $body,
            ['Content-Type' => 'application/xml'],
        );

        $this->assertSame(
            405,
            $response->getStatus(),
            'MKCALENDAR on an arbitrary URI must return 405 Method Not Allowed',
        );
    }

    // -- Calendar-schedule capability ----------------------------------------

    public function testOptionsAdvertisesCalendarSchedule(): void
    {
        $response = $this->harness->options('/dav/calendars/admin/default/');
        $this->assertContains($response->getStatus(), [200, 207]);

        $dav = $response->getHeader('DAV') ?? '';
        $this->assertStringContainsString('calendar-access', $dav);
        // Schedule\Plugin is now registered — its compliance class must
        // also appear so scheduling-aware clients discover the capability.
        $this->assertStringContainsString(
            'calendar-auto-schedule',
            $dav,
            'Schedule\\Plugin should advertise calendar-auto-schedule in the DAV header',
        );
    }

    // -- calendar-query time-range exclusion ---------------------------------

    public function testCalendarQueryTimeRangeExcludesEventsOutsideRange(): void
    {
        // Create two events: one inside the query range, one after it.
        // Sabre's CalDAV plugin post-filters by time-range so the
        // excluded event must not appear in the response.
        $inUid = 'range-in-' . bin2hex(random_bytes(4));
        $outUid = 'range-out-' . bin2hex(random_bytes(4));

        $this->harness->putIcs(
            "/dav/calendars/admin/default/{$inUid}.ics",
            $this->sampleEvent($inUid, 'In Range Event', '20260601T090000Z'),
        );
        $this->harness->putIcs(
            "/dav/calendars/admin/default/{$outUid}.ics",
            $this->sampleEvent($outUid, 'Out Of Range Event', '20270601T090000Z'),
        );

        $response = $this->harness->calendarQuery(
            '/dav/calendars/admin/default/',
            '20260101T000000Z',
            '20260701T000000Z',
        );

        $this->assertSame(207, $response->getStatus());
        $body = $response->getBodyAsString();
        $this->assertStringContainsString(
            'In Range Event',
            $body,
            'matching event should be returned',
        );
        $this->assertStringNotContainsString(
            'Out Of Range Event',
            $body,
            'time-range filter must exclude events whose DTSTART is outside the window',
        );
    }

    // -- Multi-event PROPFIND listing ----------------------------------------

    public function testPropfindDepth1ListsAllEventsOnCalendar(): void
    {
        // Create three events — PROPFIND depth=1 should list every one.
        $uids = [];
        for ($i = 0; $i < 3; $i++) {
            $uid = 'list-' . bin2hex(random_bytes(4));
            $this->harness->putIcs(
                "/dav/calendars/admin/default/{$uid}.ics",
                $this->sampleEvent($uid, "Listing Event {$i}", '20260601T090000Z'),
            );
            $uids[] = $uid;
        }

        $response = $this->harness->propfind(
            '/dav/calendars/admin/default/',
            ['{DAV:}getetag', '{urn:ietf:params:xml:ns:caldav}calendar-data'],
            depth: 1,
        );

        $this->assertSame(207, $response->getStatus());
        $body = $response->getBodyAsString();
        foreach ($uids as $i => $uid) {
            $this->assertStringContainsString(
                "Listing Event {$i}",
                $body,
                "PROPFIND depth=1 should list event {$i} (uid={$uid})",
            );
        }
    }

    // -- Calendar-level DELETE protection ------------------------------------

    public function testDeleteOnCalendarCollectionIsForbidden(): void
    {
        // Calendars are not user-deletable via CalDAV. deleteCalendar
        // throws Forbidden (403), so a client's attempt to `DELETE
        // /dav/calendars/admin/default/` cannot cascade through the
        // collection's children.
        $response = $this->harness->invoke('DELETE', '/dav/calendars/admin/default/');
        $this->assertSame(
            403,
            $response->getStatus(),
            'DELETE on a calendar collection must return 403 Forbidden',
        );
    }

    public function testDeleteOnCalendarCollectionLeavesEventsIntact(): void
    {
        // Regression guard: confirm the blocked DELETE does not silently
        // remove events from the collection. Create an event, try to
        // delete the whole calendar, then verify the event is still
        // there.
        $uid = 'protect-' . bin2hex(random_bytes(4));
        $eventUri = "/dav/calendars/admin/default/{$uid}.ics";
        $this->harness->putIcs($eventUri, $this->sampleEvent($uid, 'Survivor', '20260601T090000Z'));

        $this->harness->invoke('DELETE', '/dav/calendars/admin/default/');

        $after = $this->harness->get($eventUri);
        $this->assertSame(200, $after->getStatus(), 'Event must survive a blocked collection DELETE');
        $this->assertStringContainsString('SUMMARY:Survivor', $after->getBodyAsString());
    }

    // -- Nonexistent principal ------------------------------------------------

    public function testPropfindOnNonexistentUserCalendar(): void
    {
        // There's no 'ghost' user in the test DB. A request to their
        // calendar should fail cleanly, not with a 5xx.
        $response = $this->harness->propfind('/dav/calendars/ghost/default/', [
            '{DAV:}displayname',
        ]);

        $this->assertGreaterThanOrEqual(400, $response->getStatus());
        $this->assertLessThan(
            500,
            $response->getStatus(),
            'PROPFIND on nonexistent user should return 4xx, not 5xx',
        );
    }

    // -- Content negotiation edge -------------------------------------------

    public function testGetOnCalendarCollectionReturnsWellDefinedStatus(): void
    {
        // Some clients accidentally GET the collection URL instead of
        // individual events. Verify the server returns a well-defined
        // status — 200 (directory listing from Browser plugin),
        // 4xx (method not allowed), or 501 (not implemented) are all
        // acceptable; anything else indicates a fault.
        $response = $this->harness->get('/dav/calendars/admin/default/');
        $status = $response->getStatus();
        $this->assertTrue(
            $status === 200 || $status === 501 || ($status >= 400 && $status < 500),
            "GET on calendar collection: expected 200/4xx/501, got {$status}",
        );
    }
}
