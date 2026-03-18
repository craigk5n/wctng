<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\Seo\EventPageController;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\UserPreference;

final class SeoEventPageIntegrationTest extends IntegrationTestCase
{
    private EventPageController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new EventPageController($this->factory);
    }

    private function createPublicEvent(string $title, string $createdBy = 'alice'): int
    {
        $event = new Event(
            id: new EventId(0),
            uid: 'seo-' . bin2hex(random_bytes(4)) . '@test',
            name: $title,
            description: '<p>Test event description</p>',
            location: 'Room 42',
            start: new \DateTimeImmutable('2026-06-15 14:00:00'),
            duration: 60,
            createdBy: $createdBy,
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
        );
        $this->factory->getEventService()->createEvent($event, $this->normalUser);
        $created = $this->factory->getEventRepository()->findByUid($event->uid());
        return $created !== null ? $created->id()->value() : 0;
    }

    public function testReturns404WhenSeoDisabled(): void
    {
        $eventId = $this->createPublicEvent('Hidden Event');

        $response = $this->controller->detail('alice', $eventId);
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testReturns404WhenUserNotPublic(): void
    {
        $this->factory->getConfigService()->updateSetting('ENABLE_SEO_PAGES', 'Y');
        $eventId = $this->createPublicEvent('No Public');

        // alice hasn't enabled public calendar
        $response = $this->controller->detail('alice', $eventId);
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testRendersHtmlWhenEligible(): void
    {
        $this->factory->getConfigService()->updateSetting('ENABLE_SEO_PAGES', 'Y');
        $this->factory->getUserRepository()->savePreference('alice', new UserPreference('public_calendar_enabled', 'Y'));

        $eventId = $this->createPublicEvent('SEO Visible Event');

        $response = $this->controller->detail('alice', $eventId);
        $this->assertSame(200, $response->getStatusCode());

        $html = (string) $response->getContent();
        $this->assertStringContainsString('SEO Visible Event', $html);
        $this->assertStringContainsString('<h1>', $html);
        $this->assertStringContainsString('Room 42', $html);
        $this->assertStringContainsString("<title>SEO Visible Event", $html);
        $this->assertStringContainsString('<meta name="description"', $html);
        $this->assertStringNotContainsString('noindex', $html);
    }

    public function testAddsNoindexWhenUserOptedOut(): void
    {
        $this->factory->getConfigService()->updateSetting('ENABLE_SEO_PAGES', 'Y');
        $this->factory->getUserRepository()->savePreference('alice', new UserPreference('public_calendar_enabled', 'Y'));
        $this->factory->getUserRepository()->savePreference('alice', new UserPreference('seo_indexing_enabled', 'N'));

        $eventId = $this->createPublicEvent('Noindex Event');

        $response = $this->controller->detail('alice', $eventId);
        $this->assertSame(200, $response->getStatusCode());

        $html = (string) $response->getContent();
        $this->assertStringContainsString('noindex', $html);
        $this->assertStringContainsString('Noindex Event', $html);
    }

    public function testReturns404ForPrivateEvent(): void
    {
        $this->factory->getConfigService()->updateSetting('ENABLE_SEO_PAGES', 'Y');
        $this->factory->getUserRepository()->savePreference('alice', new UserPreference('public_calendar_enabled', 'Y'));

        // Create a private event
        $event = new Event(
            id: new EventId(0),
            uid: 'seo-private@test',
            name: 'Private Meeting',
            description: '',
            location: '',
            start: new \DateTimeImmutable('2026-06-15 10:00:00'),
            duration: 60,
            createdBy: 'alice',
            type: EventType::EVENT,
            access: AccessLevel::PRIVATE,
        );
        $this->factory->getEventService()->createEvent($event, $this->normalUser);
        $created = $this->factory->getEventRepository()->findByUid('seo-private@test');
        $eventId = $created !== null ? $created->id()->value() : 0;

        $response = $this->controller->detail('alice', $eventId);
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testReturns404ForNonexistentEvent(): void
    {
        $this->factory->getConfigService()->updateSetting('ENABLE_SEO_PAGES', 'Y');
        $this->factory->getUserRepository()->savePreference('alice', new UserPreference('public_calendar_enabled', 'Y'));

        $response = $this->controller->detail('alice', 99999);
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testHasOpenGraphMetaTags(): void
    {
        $this->factory->getConfigService()->updateSetting('ENABLE_SEO_PAGES', 'Y');
        $this->factory->getUserRepository()->savePreference('alice', new UserPreference('public_calendar_enabled', 'Y'));

        $eventId = $this->createPublicEvent('OG Test Event');
        $response = $this->controller->detail('alice', $eventId);
        $html = (string) $response->getContent();

        $this->assertStringContainsString('<meta property="og:title" content="OG Test Event">', $html);
        $this->assertStringContainsString('<meta property="og:description"', $html);
        $this->assertStringContainsString('<meta property="og:type" content="website">', $html);
        $this->assertStringContainsString('<meta property="og:url"', $html);
    }

    public function testHasTwitterCardMetaTags(): void
    {
        $this->factory->getConfigService()->updateSetting('ENABLE_SEO_PAGES', 'Y');
        $this->factory->getUserRepository()->savePreference('alice', new UserPreference('public_calendar_enabled', 'Y'));

        $eventId = $this->createPublicEvent('Twitter Test');
        $response = $this->controller->detail('alice', $eventId);
        $html = (string) $response->getContent();

        $this->assertStringContainsString('<meta name="twitter:card" content="summary">', $html);
        $this->assertStringContainsString('<meta name="twitter:title" content="Twitter Test">', $html);
        $this->assertStringContainsString('<meta name="twitter:description"', $html);
    }

    public function testHasBackLink(): void
    {
        $this->factory->getConfigService()->updateSetting('ENABLE_SEO_PAGES', 'Y');
        $this->factory->getUserRepository()->savePreference('alice', new UserPreference('public_calendar_enabled', 'Y'));

        $eventId = $this->createPublicEvent('Link Test');
        $response = $this->controller->detail('alice', $eventId);
        $html = (string) $response->getContent();

        $this->assertStringContainsString('/public/alice', $html);
        $this->assertStringContainsString('Back to', $html);
    }
}
