<?php

declare(strict_types=1);

namespace App\Tests\Integration\CalDav;

use App\Tests\Integration\IntegrationTestCase;

/**
 * Advanced CalDAV coverage:
 *   - VTODO (task) round-trip via CalDAV
 *   - VJOURNAL round-trip via CalDAV
 *   - VALARM reminders survive round-trip
 *   - If-Match / If-None-Match optimistic concurrency control
 *   - calendar-query prop-filter (text-match on SUMMARY)
 *   - principal PROPFIND
 *   - POST rejection on read-only collections
 */
final class CalDavAdvancedTest extends IntegrationTestCase
{
    private CalDavServerHarness $harness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->harness = new CalDavServerHarness($this->factory, 'admin');
    }

    // -- VTODO ---------------------------------------------------------------

    public function testVtodoRoundTrip(): void
    {
        $uid = 'task-' . bin2hex(random_bytes(4));
        $uri = "/dav/calendars/admin/default/{$uid}.ics";
        $ics = "BEGIN:VCALENDAR\r\n"
            . "VERSION:2.0\r\n"
            . "PRODID:-//test//EN\r\n"
            . "BEGIN:VTODO\r\n"
            . "UID:{$uid}\r\n"
            . "DTSTAMP:20260601T080000Z\r\n"
            . "SUMMARY:Write quarterly report\r\n"
            . "DUE:20260610T170000Z\r\n"
            . "STATUS:NEEDS-ACTION\r\n"
            . "END:VTODO\r\n"
            . "END:VCALENDAR\r\n";

        $put = $this->harness->putIcs($uri, $ics);
        $this->assertContains(
            $put->getStatus(),
            [201, 204],
            'PUT VTODO should succeed: ' . $put->getBodyAsString(),
        );

        // Verify round-trip: PROPFIND the calendar home and check the
        // task is listed somewhere (href format may be task-{id}.ics).
        $list = $this->harness->propfind('/dav/calendars/admin/default/', [
            '{DAV:}resourcetype',
            '{urn:ietf:params:xml:ns:caldav}calendar-data',
        ], depth: 1);

        $this->assertSame(207, $list->getStatus());
        $body = $list->getBodyAsString();
        $this->assertStringContainsString(
            'Write quarterly report',
            $body,
            'VTODO SUMMARY should appear in calendar listing',
        );
    }

    // -- VJOURNAL ------------------------------------------------------------

    public function testVjournalRoundTrip(): void
    {
        $uid = 'journal-' . bin2hex(random_bytes(4));
        $uri = "/dav/calendars/admin/default/{$uid}.ics";
        $ics = "BEGIN:VCALENDAR\r\n"
            . "VERSION:2.0\r\n"
            . "PRODID:-//test//EN\r\n"
            . "BEGIN:VJOURNAL\r\n"
            . "UID:{$uid}\r\n"
            . "DTSTAMP:20260601T080000Z\r\n"
            . "DTSTART;VALUE=DATE:20260601\r\n"
            . "SUMMARY:Daily Note\r\n"
            . "DESCRIPTION:Reviewed sprint priorities with the team.\r\n"
            . "END:VJOURNAL\r\n"
            . "END:VCALENDAR\r\n";

        $put = $this->harness->putIcs($uri, $ics);
        $this->assertContains(
            $put->getStatus(),
            [201, 204],
            'PUT VJOURNAL should succeed: ' . $put->getBodyAsString(),
        );

        $list = $this->harness->propfind('/dav/calendars/admin/default/', [
            '{DAV:}resourcetype',
            '{urn:ietf:params:xml:ns:caldav}calendar-data',
        ], depth: 1);

        $this->assertSame(207, $list->getStatus());
        $this->assertStringContainsString(
            'Daily Note',
            $list->getBodyAsString(),
            'VJOURNAL SUMMARY should appear in calendar listing',
        );
    }

    // -- VALARM --------------------------------------------------------------

    public function testVAlarmReminderSurvivesRoundTrip(): void
    {
        $uid = 'valarm-' . bin2hex(random_bytes(4));
        $uri = "/dav/calendars/admin/default/{$uid}.ics";
        $ics = "BEGIN:VCALENDAR\r\n"
            . "VERSION:2.0\r\n"
            . "PRODID:-//test//EN\r\n"
            . "BEGIN:VEVENT\r\n"
            . "UID:{$uid}\r\n"
            . "DTSTAMP:20260601T080000Z\r\n"
            . "DTSTART:20260601T140000Z\r\n"
            . "DTEND:20260601T150000Z\r\n"
            . "SUMMARY:Dentist appointment\r\n"
            . "BEGIN:VALARM\r\n"
            . "ACTION:DISPLAY\r\n"
            . "TRIGGER:-PT15M\r\n"
            . "DESCRIPTION:Leave for dentist\r\n"
            . "END:VALARM\r\n"
            . "END:VEVENT\r\n"
            . "END:VCALENDAR\r\n";

        $put = $this->harness->putIcs($uri, $ics);
        $this->assertContains($put->getStatus(), [201, 204]);

        $body = $this->harness->get($uri)->getBodyAsString();
        $this->assertStringContainsString('BEGIN:VALARM', $body, 'VALARM block should round-trip');
        $this->assertStringContainsString('TRIGGER:-PT15M', $body, 'TRIGGER should round-trip');
    }

    // -- Optimistic concurrency: If-Match / If-None-Match -------------------

    public function testIfNoneMatchStarPreventsOverwriteOnCreate(): void
    {
        // RFC 2616: If-None-Match: * means "create only if resource does
        // not already exist". A PUT with this header on an existing
        // resource must return 412 Precondition Failed.
        $uid = 'concur-' . bin2hex(random_bytes(4));
        $uri = "/dav/calendars/admin/default/{$uid}.ics";
        $ics = $this->sampleEventIcs($uid, 'Original');

        // First PUT with If-None-Match: * should succeed (resource new)
        $first = $this->harness->invoke('PUT', $uri, $ics, [
            'Content-Type' => 'text/calendar',
            'If-None-Match' => '*',
        ]);
        $this->assertContains($first->getStatus(), [201, 204]);

        // Second PUT with If-None-Match: * on the same URI should fail
        $second = $this->harness->invoke('PUT', $uri, $ics, [
            'Content-Type' => 'text/calendar',
            'If-None-Match' => '*',
        ]);
        $this->assertSame(
            412,
            $second->getStatus(),
            'If-None-Match: * on an existing resource must return 412 Precondition Failed',
        );
    }

    public function testIfMatchWithStaleEtagReturnsPreconditionFailed(): void
    {
        // Optimistic concurrency: a client that holds an old ETag must
        // get 412 when trying to update a resource that was modified in
        // the meantime.
        $uid = 'ifmatch-' . bin2hex(random_bytes(4));
        $uri = "/dav/calendars/admin/default/{$uid}.ics";

        $this->harness->putIcs($uri, $this->sampleEventIcs($uid, 'v1'));

        // Update with a deliberately stale ETag
        $response = $this->harness->invoke('PUT', $uri, $this->sampleEventIcs($uid, 'v2'), [
            'Content-Type' => 'text/calendar',
            'If-Match' => '"deadbeef-stale-etag"',
        ]);

        $this->assertSame(412, $response->getStatus());

        // The original event must be unchanged
        $body = $this->harness->get($uri)->getBodyAsString();
        $this->assertStringContainsString('SUMMARY:v1', $body);
        $this->assertStringNotContainsString('SUMMARY:v2', $body);
    }

    public function testIfMatchWithCurrentEtagSucceeds(): void
    {
        $uid = 'ifmatch-ok-' . bin2hex(random_bytes(4));
        $uri = "/dav/calendars/admin/default/{$uid}.ics";

        $put = $this->harness->putIcs($uri, $this->sampleEventIcs($uid, 'v1'));
        $etag = $put->getHeader('ETag');
        $this->assertNotNull($etag, 'PUT must return an ETag to enable concurrency control');

        $update = $this->harness->invoke('PUT', $uri, $this->sampleEventIcs($uid, 'v2'), [
            'Content-Type' => 'text/calendar',
            'If-Match' => $etag,
        ]);
        $this->assertContains($update->getStatus(), [200, 204]);
    }

    public function testTheEtagOfAnUntouchedEventDoesNotChangeAsTimePasses(): void
    {
        // VObject stamps DTSTAMP with the current second when the serialiser
        // does not supply one, and the ETag is an md5 of that serialisation.
        // The ETag therefore used to change once a second for an event nobody
        // had touched, which re-fetched every object on each sync and failed
        // If-Match updates that straddled a second boundary.
        $uid = 'etag-stable-' . bin2hex(random_bytes(4));
        $uri = "/dav/calendars/admin/default/{$uid}.ics";
        $this->harness->putIcs($uri, $this->sampleEventIcs($uid, 'Unchanged'));

        // Backdate the revision so "now" and the stored stamp cannot agree by
        // accident -- without this the assertion would pass either way for an
        // event created in the same second.
        $this->pdo
            ->prepare('UPDATE webcal_entry SET cal_mod_date = 20200102, cal_mod_time = 030405 WHERE cal_uid = :uid')
            ->execute(['uid' => $uid]);

        $first = $this->harness->get($uri);
        self::assertSame(200, $first->getStatus());
        self::assertStringContainsString('DTSTAMP:20200102T030405Z', $first->getBodyAsString());

        $etag = $first->getHeader('ETag');
        self::assertNotNull($etag);
        self::assertSame($etag, $this->harness->get($uri)->getHeader('ETag'));

        // And it still tracks revisions: an ETag that ignored them would be
        // useless as a validator.
        $this->pdo
            ->prepare('UPDATE webcal_entry SET cal_mod_date = 20200102, cal_mod_time = 030406 WHERE cal_uid = :uid')
            ->execute(['uid' => $uid]);

        self::assertNotSame($etag, $this->harness->get($uri)->getHeader('ETag'));
    }

    // -- calendar-query with text filter -------------------------------------

    public function testCalendarQueryWithTextFilterMatchesSummary(): void
    {
        // Create two events; query with a text-match filter on SUMMARY;
        // only the matching one should appear.
        $matching = 'text-match-' . bin2hex(random_bytes(4));
        $other = 'text-other-' . bin2hex(random_bytes(4));
        $this->harness->putIcs(
            "/dav/calendars/admin/default/{$matching}.ics",
            $this->sampleEventIcs($matching, 'Sprint Planning'),
        );
        $this->harness->putIcs(
            "/dav/calendars/admin/default/{$other}.ics",
            $this->sampleEventIcs($other, 'Lunch'),
        );

        $body = <<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
              <d:prop>
                <d:getetag/>
                <c:calendar-data/>
              </d:prop>
              <c:filter>
                <c:comp-filter name="VCALENDAR">
                  <c:comp-filter name="VEVENT">
                    <c:prop-filter name="SUMMARY">
                      <c:text-match collation="i;ascii-casemap">Sprint</c:text-match>
                    </c:prop-filter>
                  </c:comp-filter>
                </c:comp-filter>
              </c:filter>
            </c:calendar-query>
            XML;

        $response = $this->harness->invoke(
            'REPORT',
            '/dav/calendars/admin/default/',
            $body,
            ['Depth' => '1', 'Content-Type' => 'application/xml'],
        );

        $this->assertSame(207, $response->getStatus());
        $text = $response->getBodyAsString();
        $this->assertStringContainsString('Sprint Planning', $text, 'matching event should appear');
        $this->assertStringNotContainsString(
            'Lunch',
            $text,
            'text-match prop-filter must exclude non-matching events',
        );
    }

    // -- Principal PROPFIND --------------------------------------------------

    public function testPropfindOnPrincipalReturnsUserInfo(): void
    {
        $response = $this->harness->propfind('/dav/principals/admin/', [
            '{DAV:}displayname',
            '{urn:ietf:params:xml:ns:caldav}calendar-home-set',
        ]);

        $this->assertSame(207, $response->getStatus());
        $body = $response->getBodyAsString();
        // calendar-home-set tells clients where to find the user's calendars
        $this->assertStringContainsString(
            'calendar-home-set',
            $body,
            'principal PROPFIND should expose calendar-home-set for client discovery',
        );
    }

    // -- POST on a calendar collection ---------------------------------------

    public function testPostOnCalendarHomeHandledGracefully(): void
    {
        // Clients never POST to the calendar home (it's read-only for
        // direct writes). A misbehaving client should get a clean,
        // well-defined rejection — either a 4xx or 501 Not Implemented.
        $response = $this->harness->invoke(
            'POST',
            '/dav/calendars/admin/',
            'anything',
            ['Content-Type' => 'text/plain'],
        );

        $status = $response->getStatus();
        $this->assertTrue(
            $status === 501 || ($status >= 400 && $status < 500),
            "Unsupported POST should return a 4xx or 501, got {$status}",
        );
    }

    // -- Helpers -------------------------------------------------------------

    private function sampleEventIcs(string $uid, string $summary): string
    {
        return "BEGIN:VCALENDAR\r\n"
            . "VERSION:2.0\r\n"
            . "PRODID:-//test//EN\r\n"
            . "BEGIN:VEVENT\r\n"
            . "UID:{$uid}\r\n"
            . "DTSTAMP:20260601T080000Z\r\n"
            . "DTSTART:20260601T090000Z\r\n"
            . "DTEND:20260601T100000Z\r\n"
            . "SUMMARY:{$summary}\r\n"
            . "END:VEVENT\r\n"
            . "END:VCALENDAR\r\n";
    }
}
