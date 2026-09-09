<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\JsonLdGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;

final class JsonLdGeneratorTest extends TestCase
{
    private JsonLdGenerator $generator;
    private User $user;

    protected function setUp(): void
    {
        $this->generator = new JsonLdGenerator();
        $this->user = new User('alice', 'Alice', 'Smith', 'alice@example.com', false, true);
    }

    private function makeEvent(
        string $name = 'Test Event',
        string $location = 'Room A',
        string $description = 'A test event',
        ?string $status = null,
    ): Event {
        return new Event(
            id: new EventId(1),
            uid: 'test@example.com',
            name: $name,
            description: $description,
            location: $location,
            start: new \DateTimeImmutable('2026-06-15 14:00:00'),
            duration: 60,
            createdBy: 'alice',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
            status: $status,
        );
    }

    public function testGeneratesValidJsonLd(): void
    {
        $event = $this->makeEvent();
        $data = $this->generator->buildEventData($event, $this->user);

        $this->assertSame('https://schema.org', $data['@context']);
        $this->assertSame('Event', $data['@type']);
        $this->assertSame('Test Event', $data['name']);
        $this->assertStringContainsString('2026-06-15', $data['startDate']);
        $this->assertSame('A test event', $data['description']);
    }

    public function testPhysicalLocation(): void
    {
        $event = $this->makeEvent(location: 'Room A');
        $data = $this->generator->buildEventData($event, $this->user);

        $this->assertSame('Place', $data['location']['@type']);
        $this->assertSame('Room A', $data['location']['name']);
        $this->assertSame('https://schema.org/OfflineEventAttendanceMode', $data['eventAttendanceMode']);
    }

    public function testVirtualLocation(): void
    {
        $event = $this->makeEvent(location: 'https://zoom.us/j/123456');
        $data = $this->generator->buildEventData($event, $this->user);

        $this->assertSame('VirtualLocation', $data['location']['@type']);
        $this->assertSame('https://zoom.us/j/123456', $data['location']['url']);
        $this->assertSame('https://schema.org/OnlineEventAttendanceMode', $data['eventAttendanceMode']);
    }

    public function testOrganizer(): void
    {
        $data = $this->generator->buildEventData($this->makeEvent(), $this->user);

        $this->assertSame('Person', $data['organizer']['@type']);
        $this->assertSame('Alice Smith', $data['organizer']['name']);
        $this->assertSame('alice@example.com', $data['organizer']['email']);
    }

    public function testScheduledStatus(): void
    {
        $data = $this->generator->buildEventData($this->makeEvent(), $this->user);
        $this->assertSame('https://schema.org/EventScheduled', $data['eventStatus']);
    }

    public function testCancelledStatus(): void
    {
        $event = $this->makeEvent(status: 'cancelled');
        $data = $this->generator->buildEventData($event, $this->user);
        $this->assertSame('https://schema.org/EventCancelled', $data['eventStatus']);
    }

    public function testTentativeStatus(): void
    {
        $event = $this->makeEvent(status: 'needs_approval');
        $data = $this->generator->buildEventData($event, $this->user);
        $this->assertSame('https://schema.org/EventPostponed', $data['eventStatus']);
    }

    public function testCanonicalUrl(): void
    {
        $data = $this->generator->buildEventData($this->makeEvent(), $this->user, '/public/alice/event/1');
        $this->assertSame('/public/alice/event/1', $data['url']);
    }

    public function testNoLocationOmitsField(): void
    {
        $event = $this->makeEvent(location: '');
        $data = $this->generator->buildEventData($event, $this->user);
        $this->assertArrayNotHasKey('location', $data);
    }

    public function testHtmlStrippedFromDescription(): void
    {
        $event = $this->makeEvent(description: '<p>Hello <strong>bold</strong></p>');
        $data = $this->generator->buildEventData($event, $this->user);
        $this->assertSame('Hello bold', $data['description']);
    }

    public function testScriptTagOutput(): void
    {
        $output = $this->generator->generateEventJsonLd($this->makeEvent(), $this->user);
        $this->assertStringStartsWith('<script type="application/ld+json">', $output);
        $this->assertStringEndsWith('</script>', $output);
        $this->assertStringContainsString('"@context": "https://schema.org"', $output);
    }

    public function testBreadcrumbListForEventDetail(): void
    {
        $data = $this->generator->buildBreadcrumbData('alice', 'Alice Smith', '/public/alice/event/1', 'Test Event');

        $this->assertSame('https://schema.org', $data['@context']);
        $this->assertSame('BreadcrumbList', $data['@type']);
        $this->assertCount(3, $data['itemListElement']);

        $this->assertSame(1, $data['itemListElement'][0]['position']);
        $this->assertSame('Alice Smith\'s Calendar', $data['itemListElement'][0]['item']['name']);
        $this->assertSame('/public/alice', $data['itemListElement'][0]['item']['@id']);

        $this->assertSame(2, $data['itemListElement'][1]['position']);
        $this->assertSame('Events', $data['itemListElement'][1]['item']['name']);
        $this->assertSame('/public/alice/events', $data['itemListElement'][1]['item']['@id']);

        $this->assertSame(3, $data['itemListElement'][2]['position']);
        $this->assertSame('Test Event', $data['itemListElement'][2]['item']['name']);
        $this->assertSame('/public/alice/event/1', $data['itemListElement'][2]['item']['@id']);
    }

    public function testBreadcrumbListForEventIndex(): void
    {
        $data = $this->generator->buildBreadcrumbData('alice', 'Alice Smith', '/public/alice/events');

        $this->assertSame('BreadcrumbList', $data['@type']);
        $this->assertCount(2, $data['itemListElement']);

        $this->assertSame('Alice Smith\'s Calendar', $data['itemListElement'][0]['item']['name']);
        $this->assertSame('Events', $data['itemListElement'][1]['item']['name']);
    }

    public function testBreadcrumbJsonLdScriptTag(): void
    {
        $output = $this->generator->generateBreadcrumbJsonLd('alice', 'Alice Smith', '/public/alice/events');
        $this->assertStringStartsWith('<script type="application/ld+json">', $output);
        $this->assertStringEndsWith('</script>', $output);
        $this->assertStringContainsString('BreadcrumbList', $output);
    }

    // --- the fields the array carries ---

    public function testBothEndsOfTheEventAreCarriedAsAtomTimestamps(): void
    {
        // endDate was the one field nothing read, so `'endDate' => x` could
        // decay into a comparison and leave the key off the document entirely.
        $data = $this->generator->buildEventData($this->makeEvent(), $this->user);

        $this->assertSame('2026-06-15T14:00:00+00:00', $data['startDate']);
        $this->assertSame('2026-06-15T15:00:00+00:00', $data['endDate']);
    }

    // --- status mapping ---

    /** @return iterable<string, array{string|null, string}> */
    public static function statuses(): iterable
    {
        yield 'CANCELLED' => ['CANCELLED', 'https://schema.org/EventCancelled'];
        yield 'cancelled' => ['cancelled', 'https://schema.org/EventCancelled'];
        yield 'rejected' => ['rejected', 'https://schema.org/EventCancelled'];
        yield 'TENTATIVE' => ['TENTATIVE', 'https://schema.org/EventPostponed'];
        yield 'tentative' => ['tentative', 'https://schema.org/EventPostponed'];
        yield 'needs_approval' => ['needs_approval', 'https://schema.org/EventPostponed'];
        yield 'no status at all' => [null, 'https://schema.org/EventScheduled'];
        yield 'something unrecognised' => ['whatever', 'https://schema.org/EventScheduled'];
    }

    #[DataProvider('statuses')]
    public function testEveryStatusSpellingMapsToItsSchemaValue(?string $status, string $expected): void
    {
        // Each spelling is its own match arm, and a removed arm falls through
        // to "scheduled" -- which would tell a search engine that a cancelled
        // event is going ahead.
        $data = $this->generator->buildEventData($this->makeEvent(status: $status), $this->user);

        $this->assertSame($expected, $data['eventStatus']);
    }

    // --- location and attendance mode ---

    /** @return iterable<string, array{string, string, string}> */
    public static function locations(): iterable
    {
        yield 'a room is a place' => ['Room A', 'Place', 'https://schema.org/OfflineEventAttendanceMode'];
        yield 'a link is virtual' => ['https://meet.example.com/x', 'VirtualLocation', 'https://schema.org/OnlineEventAttendanceMode'];
        yield 'an upper-case link is still virtual' => ['HTTPS://MEET.EXAMPLE.COM/X', 'VirtualLocation', 'https://schema.org/OnlineEventAttendanceMode'];
        yield 'http counts too' => ['http://meet.example.com/x', 'VirtualLocation', 'https://schema.org/OnlineEventAttendanceMode'];
        yield 'a room that mentions a link is a place' => ['Room A, dial in at https://meet.example.com/x', 'Place', 'https://schema.org/OfflineEventAttendanceMode'];
    }

    #[DataProvider('locations')]
    public function testTheLocationDecidesThePlaceTypeAndAttendanceMode(
        string $location,
        string $expectedType,
        string $expectedMode,
    ): void {
        // Both decisions come from `#^https?://#i`. Without the anchor a room
        // whose name mentions a link becomes a virtual event; without the flag
        // an upper-cased link stops being one.
        $data = $this->generator->buildEventData($this->makeEvent(location: $location), $this->user);

        $this->assertSame($expectedType, $data['location']['@type']);
        $this->assertSame($expectedMode, $data['eventAttendanceMode']);
    }

    public function testAnEventWithNoLocationIsOffline(): void
    {
        $data = $this->generator->buildEventData($this->makeEvent(location: ''), $this->user);

        $this->assertSame('https://schema.org/OfflineEventAttendanceMode', $data['eventAttendanceMode']);
    }

    // --- breadcrumbs ---

    public function testEveryBreadcrumbIsAListItem(): void
    {
        // The @type on each entry is what makes the list a BreadcrumbList to a
        // consumer, and no test read it.
        $data = $this->generator->buildBreadcrumbData('alice', 'Alice Smith', '/public/alice/event/1', 'Test Event');

        $this->assertSame(
            ['ListItem', 'ListItem', 'ListItem'],
            array_column($data['itemListElement'], '@type'),
        );
    }

    public function testTheIndexBreadcrumbsAreListItemsToo(): void
    {
        $data = $this->generator->buildBreadcrumbData('alice', 'Alice Smith', '/public/alice/events');

        $this->assertSame(['ListItem', 'ListItem'], array_column($data['itemListElement'], '@type'));
    }

    // --- how the document is rendered ---

    public function testSlashesAreLeftAlone(): void
    {
        // Escaped slashes are valid JSON but unreadable, and the encoder does
        // it by default.
        $output = $this->generator->generateEventJsonLd($this->makeEvent(), $this->user);

        $this->assertStringContainsString('https://schema.org', $output);
        $this->assertStringNotContainsString('https:\/\/schema.org', $output);
    }

    public function testTheDocumentIsPrettyPrinted(): void
    {
        $output = $this->generator->generateEventJsonLd($this->makeEvent(), $this->user);

        $this->assertStringContainsString("{\n    \"@context\"", $output);
    }

    public function testNonAsciiSurvivesAsItself(): void
    {
        $output = $this->generator->generateEventJsonLd($this->makeEvent(name: 'Café Meeting'), $this->user);

        $this->assertStringContainsString('Café Meeting', $output);
        $this->assertStringNotContainsString('Caf\u00e9', $output);
    }

    public function testTheBreadcrumbDocumentIsRenderedTheSameWay(): void
    {
        // Its own json_encode call, with its own copy of the flags.
        $output = $this->generator->generateBreadcrumbJsonLd('alice', 'Café Owner', '/public/alice/events');

        $this->assertStringContainsString('https://schema.org', $output);
        $this->assertStringNotContainsString('https:\/\/schema.org', $output);
        $this->assertStringContainsString("{\n    \"@context\"", $output);
        $this->assertStringContainsString('Café Owner', $output);
    }

    // --- the script wrapper ---

    public function testWithoutANonceTheScriptTagHasNoNonceAttribute(): void
    {
        $output = $this->generator->generateEventJsonLd($this->makeEvent(), $this->user);

        $this->assertStringStartsWith('<script type="application/ld+json">{', $output);
        $this->assertStringNotContainsString('nonce', $output);
    }

    public function testANonceIsEmittedAsAnAttribute(): void
    {
        $output = $this->generator->generateEventJsonLd($this->makeEvent(), $this->user, nonce: 'abc123');

        $this->assertStringStartsWith('<script type="application/ld+json" nonce="abc123">{', $output);
        $this->assertStringEndsWith('}</script>', $output);
    }

    public function testANonceIsEscapedIntoTheAttribute(): void
    {
        // A nonce comes from the CSP header rather than a user, but it is
        // interpolated into markup, so the escaping is the difference between
        // an attribute and an injection point.
        $output = $this->generator->generateEventJsonLd(
            $this->makeEvent(),
            $this->user,
            nonce: 'a"><script>alert(1)</script>',
        );

        $this->assertStringStartsWith(
            '<script type="application/ld+json" nonce="a&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;">',
            $output,
        );
    }

    public function testTheBreadcrumbScriptCarriesItsNonceToo(): void
    {
        $output = $this->generator->generateBreadcrumbJsonLd('alice', 'Alice Smith', '/public/alice/events', null, 'xyz789');

        $this->assertStringStartsWith('<script type="application/ld+json" nonce="xyz789">{', $output);
    }
}
