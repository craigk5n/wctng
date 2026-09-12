<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller\Seo;

use App\Controller\Seo\EventPageController;
use App\Service\CustomHtmlProvider;
use App\Service\SeoEligibilityService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\ConfigService;
use WebCalendar\Core\Application\Service\EventService;
use WebCalendar\Core\Application\Service\UserService;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Repository\ConfigRepositoryInterface;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\Repository\UserRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\UserPreference;

/**
 * What the crawlable event page puts on the page.
 *
 * The event's name reaches this page from the anonymous booking route, which
 * writes "Booking: {whatever was posted}" into it, so what happens to that
 * string on the way out is the whole question. The integration suite renders
 * the page but the mutation run does not execute it, and the location two
 * lines below the name was escaped while the name was not.
 */
final class EventPageControllerTest extends TestCase
{
    private ?Event $event = null;

    private function controller(bool $seoGlobally = true, bool $publicCalendar = true): EventPageController
    {
        $config = $this->createMock(ConfigRepositoryInterface::class);
        $config->method('get')->willReturnCallback(
            static fn(string $key): ?string => $key === 'ENABLE_SEO_PAGES' ? ($seoGlobally ? 'Y' : 'N') : null,
        );
        $configService = new ConfigService($config);

        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findByLogin')->willReturn(new User('alice', 'Alice', 'Smith', 'alice@example.com', false, true));
        $users->method('getPreferences')->willReturn(
            $publicCalendar ? [new UserPreference('public_calendar_enabled', 'Y')] : [],
        );

        $events = $this->createMock(EventRepositoryInterface::class);
        $events->method('findById')->willReturnCallback(fn(): ?Event => $this->event);

        return new EventPageController(
            new SeoEligibilityService($configService, $users),
            new UserService($users),
            new EventService($events, $users),
            $configService,
            new CustomHtmlProvider($configService),
        );
    }

    private function eventNamed(string $name, string $location = 'Room 2', ?string $status = null): void
    {
        $this->event = new Event(
            id: new EventId(7),
            uid: 'e@x',
            name: $name,
            description: 'A meeting',
            location: $location,
            start: new \DateTimeImmutable('2026-10-01 10:00:00'),
            duration: 60,
            createdBy: 'alice',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
            status: $status,
        );
    }

    private function render(): string
    {
        $response = $this->controller()->detail('alice', 7);
        self::assertSame(200, $response->getStatusCode());
        $body = $response->getContent();
        self::assertIsString($body);

        return $body;
    }

    public function testTheEventNameIsShownOnThePage(): void
    {
        $this->eventNamed('Quarterly review');

        $html = $this->render();

        $this->assertStringContainsString('<h1>Quarterly review</h1>', $html);
        $this->assertStringContainsString('<title>Quarterly review', $html);
    }

    /**
     * Anyone can put a name on this page without logging in, and it lands in a
     * title, two meta attributes, a heading and a JSON-LD block.
     *
     * Everything outside the JSON-LD blocks is HTML, so the name has to arrive
     * there escaped. The blocks themselves are cut out below by matching up to
     * their first "</script>" -- which is also how a browser ends them, so a
     * name carrying one leaves its own tail behind in what is left, and the
     * same assertion catches it.
     */
    #[DataProvider('namesThatAreMarkup')]
    public function testTheEventNameCannotCarryMarkupOntoThePage(string $name): void
    {
        $this->eventNamed($name);

        $html = $this->render();

        // Each JSON-LD block, taken the way a browser takes it -- up to its
        // first "</script>". A name that carries one cuts its block short, and
        // what is left is no longer the JSON that was encoded.
        preg_match_all('#<script type="application/ld\+json"[^>]*>(.*?)</script>#s', $html, $blocks);
        $this->assertNotSame([], $blocks[1], 'the page rendered no JSON-LD at all');
        foreach ($blocks[1] as $json) {
            $this->assertNotNull(
                json_decode($json),
                'a JSON-LD block ended early -- the event name closed it',
            );
        }

        // Everything else on the page is HTML, so the name has to arrive escaped.
        $outsideJsonLd = (string) preg_replace(
            '#<script type="application/ld\+json"[^>]*>.*?</script>#s',
            '',
            $html,
        );
        $this->assertStringNotContainsString($name, $outsideJsonLd);
        $this->assertStringContainsString(htmlspecialchars($name, \ENT_QUOTES, 'UTF-8'), $outsideJsonLd);
    }

    /** @return iterable<string, array{string}> */
    public static function namesThatAreMarkup(): iterable
    {
        yield 'a script tag' => ['Booking: <script>alert(1)</script>'];
        yield 'closing the json-ld block' => ['Booking: </script><script>alert(1)</script>'];
        yield 'an image handler' => ['Booking: <img src=x onerror=alert(1)>'];
        yield 'breaking out of an attribute' => ['Booking: "><svg onload=alert(1)>'];
        yield 'a single-quoted attribute' => ["Booking: '><svg onload=alert(1)>"];
    }

    public function testTheEscapedNameIsStillTheNameThatWasStored(): void
    {
        $this->eventNamed('Tea & biscuits');

        $html = $this->render();

        $this->assertStringContainsString('<h1>Tea &amp; biscuits</h1>', $html);
    }

    public function testAPageNobodyPublishedIsNotThere(): void
    {
        $this->eventNamed('Quarterly review');

        $this->assertSame(404, $this->controller(publicCalendar: false)->detail('alice', 7)->getStatusCode());
        $this->assertSame(404, $this->controller(seoGlobally: false)->detail('alice', 7)->getStatusCode());
    }

    /**
     * Access 'P' was the only thing this page asked about, so an entry waiting
     * for an administrator, one they refused, and one its owner deleted --
     * DeleteEventController soft-deletes by writing 'cancelled' -- all stayed
     * on a page anyone can read.
     */
    #[DataProvider('withheldStatuses')]
    public function testAnEntryThatIsNotPublishedIsNotThere(string $status): void
    {
        $this->eventNamed('Quarterly review', status: $status);

        $this->assertSame(404, $this->controller()->detail('alice', 7)->getStatusCode());
    }

    /** @return iterable<string, array{string}> */
    public static function withheldStatuses(): iterable
    {
        yield 'waiting for an administrator' => ['needs_approval'];
        yield 'refused' => ['rejected'];
        yield 'deleted by its owner' => ['cancelled'];
    }

    public function testAConfirmedEntryIsStillThere(): void
    {
        $this->eventNamed('Quarterly review', status: 'confirmed');

        $this->assertSame(200, $this->controller()->detail('alice', 7)->getStatusCode());
    }

    public function testAnEventThatIsNotThereIsNotThere(): void
    {
        $this->event = null;

        $this->assertSame(404, $this->controller()->detail('alice', 7)->getStatusCode());
    }
}
