<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\JsonLdGenerator;
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
}
