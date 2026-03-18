<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\Seo\EventIndexController;
use Symfony\Component\HttpFoundation\Request;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\UserPreference;

final class SeoEventIndexIntegrationTest extends IntegrationTestCase
{
    private EventIndexController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new EventIndexController($this->factory);

        // Enable SEO + public calendar for alice
        $this->factory->getConfigService()->updateSetting('ENABLE_SEO_PAGES', 'Y');
        $this->factory->getUserRepository()->savePreference('alice', new UserPreference('public_calendar_enabled', 'Y'));
    }

    private function createEvents(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $this->factory->getEventService()->createEvent(new Event(
                id: new EventId(0),
                uid: "idx-{$i}@test",
                name: "Event {$i}",
                description: '',
                location: "Room {$i}",
                start: new \DateTimeImmutable("2026-06-{$i} 10:00:00"),
                duration: 60,
                createdBy: 'alice',
                type: EventType::EVENT,
                access: AccessLevel::PUBLIC,
            ), $this->normalUser);
        }
    }

    public function testReturns404WhenSeoDisabled(): void
    {
        $this->factory->getConfigService()->updateSetting('ENABLE_SEO_PAGES', 'N');
        $request = Request::create('/public/alice/events');
        $response = $this->controller->index('alice', $request);
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testRendersUpcomingEventsPage(): void
    {
        $this->createEvents(3);
        $request = Request::create('/public/alice/events');
        $response = $this->controller->index('alice', $request);

        $this->assertSame(200, $response->getStatusCode());
        $html = (string) $response->getContent();

        $this->assertStringContainsString('Upcoming Events', $html);
        $this->assertStringContainsString('Event 1', $html);
        $this->assertStringContainsString('Event 2', $html);
        $this->assertStringContainsString('Event 3', $html);
        $this->assertStringContainsString('<article', $html);
        $this->assertStringContainsString('<time', $html);
        $this->assertStringContainsString('rel="canonical"', $html);
    }

    public function testRendersMonthlyArchive(): void
    {
        $this->createEvents(2);
        $request = Request::create('/public/alice/events?month=2026-06');
        $response = $this->controller->index('alice', $request);

        $html = (string) $response->getContent();
        $this->assertStringContainsString('June 2026', $html);
        $this->assertStringContainsString('Event 1', $html);
    }

    public function testLinksToEventDetailPages(): void
    {
        $this->createEvents(1);
        $request = Request::create('/public/alice/events');
        $response = $this->controller->index('alice', $request);
        $html = (string) $response->getContent();

        $this->assertStringContainsString('/public/alice/event/', $html);
    }

    public function testShowsEmptyState(): void
    {
        $request = Request::create('/public/alice/events?month=2030-01');
        $response = $this->controller->index('alice', $request);
        $html = (string) $response->getContent();

        $this->assertStringContainsString('No events found', $html);
    }

    public function testHasMetaTags(): void
    {
        $this->createEvents(1);
        $request = Request::create('/public/alice/events');
        $response = $this->controller->index('alice', $request);
        $html = (string) $response->getContent();

        $this->assertStringContainsString('<title>', $html);
        $this->assertStringContainsString('<meta name="description"', $html);
        $this->assertStringContainsString('Alice Smith', $html);
    }
}
