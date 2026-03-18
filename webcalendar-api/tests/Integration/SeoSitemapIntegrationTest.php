<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\Seo\SitemapController;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\UserPreference;

final class SeoSitemapIntegrationTest extends IntegrationTestCase
{
    private SitemapController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new SitemapController($this->factory);
    }

    private function enableSeo(string $login = 'alice'): void
    {
        $this->factory->getConfigService()->updateSetting('ENABLE_SEO_PAGES', 'Y');
        $this->factory->getUserRepository()->savePreference($login, new UserPreference('public_calendar_enabled', 'Y'));
    }

    private function createPublicEvent(string $title, string $daysOffset = '+10'): void
    {
        $this->factory->getEventService()->createEvent(new Event(
            id: new EventId(0),
            uid: 'sm-' . bin2hex(random_bytes(4)) . '@test',
            name: $title,
            description: '',
            location: 'Room 1',
            start: new \DateTimeImmutable("{$daysOffset} days 10:00:00"),
            duration: 60,
            createdBy: 'alice',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
        ), $this->normalUser);
    }

    public function testReturns404WhenSeoDisabled(): void
    {
        $response = $this->controller->sitemap();
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testReturnsEmptySitemapWhenNoEligibleUsers(): void
    {
        $this->factory->getConfigService()->updateSetting('ENABLE_SEO_PAGES', 'Y');
        // No users have public calendar enabled
        $response = $this->controller->sitemap();
        $this->assertSame(200, $response->getStatusCode());
        $xml = (string) $response->getContent();
        $this->assertStringContainsString('<urlset', $xml);
        $this->assertStringNotContainsString('<url>', $xml);
    }

    public function testIncludesEventIndexAndDetailUrls(): void
    {
        $this->enableSeo();
        $this->createPublicEvent('Sitemap Event');

        $response = $this->controller->sitemap();
        $this->assertSame(200, $response->getStatusCode());

        $xml = (string) $response->getContent();
        $this->assertStringContainsString('application/xml', $response->headers->get('Content-Type') ?? '');
        $this->assertStringContainsString('<urlset', $xml);
        $this->assertStringContainsString('/public/alice/events', $xml);
        $this->assertStringContainsString('/public/alice/event/', $xml);
        $this->assertStringContainsString('<lastmod>', $xml);
        $this->assertStringContainsString('<changefreq>', $xml);
        $this->assertStringContainsString('<priority>', $xml);
    }

    public function testUpcomingEventsGetHigherPriority(): void
    {
        $this->enableSeo();
        $this->createPublicEvent('Soon Event', '+3');

        $response = $this->controller->sitemap();
        $xml = (string) $response->getContent();

        // Event within 7 days should have 0.9 priority
        $this->assertStringContainsString('<priority>0.9</priority>', $xml);
    }

    public function testPastEventsGetLowerPriority(): void
    {
        $this->enableSeo();
        $this->createPublicEvent('Past Event', '-30');

        $response = $this->controller->sitemap();
        $xml = (string) $response->getContent();

        $this->assertStringContainsString('<priority>0.4</priority>', $xml);
        $this->assertStringContainsString('<changefreq>monthly</changefreq>', $xml);
    }

    public function testExcludesUsersWithNoindexPreference(): void
    {
        $this->enableSeo();
        $this->factory->getUserRepository()->savePreference('alice', new UserPreference('seo_indexing_enabled', 'N'));
        $this->createPublicEvent('Hidden Event');

        $response = $this->controller->sitemap();
        $xml = (string) $response->getContent();

        // User opted out — should not appear in sitemap
        $this->assertStringNotContainsString('/public/alice/', $xml);
    }

    public function testSitemapHasCacheHeaders(): void
    {
        $this->enableSeo();
        $response = $this->controller->sitemap();
        $this->assertStringContainsString('max-age=3600', $response->headers->get('Cache-Control') ?? '');
    }

    // --- robots.txt tests ---

    public function testRobotsTxtAllowsPublicDisallowsPrivate(): void
    {
        $response = $this->controller->robots();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('text/plain', $response->headers->get('Content-Type') ?? '');

        $text = (string) $response->getContent();
        $this->assertStringContainsString('Allow: /public/', $text);
        $this->assertStringContainsString('Allow: /book/', $text);
        $this->assertStringContainsString('Disallow: /api/', $text);
        $this->assertStringContainsString('Disallow: /admin/', $text);
        $this->assertStringContainsString('Disallow: /settings/', $text);
        $this->assertStringContainsString('Disallow: /dav/', $text);
        $this->assertStringContainsString('Disallow: /control/', $text);
    }

    public function testRobotsTxtIncludesSitemapWhenSeoEnabled(): void
    {
        $this->factory->getConfigService()->updateSetting('ENABLE_SEO_PAGES', 'Y');
        $response = $this->controller->robots();
        $text = (string) $response->getContent();

        $this->assertStringContainsString('Sitemap: /sitemap.xml', $text);
    }

    public function testRobotsTxtOmitsSitemapWhenSeoDisabled(): void
    {
        $response = $this->controller->robots();
        $text = (string) $response->getContent();

        $this->assertStringNotContainsString('Sitemap', $text);
    }
}
