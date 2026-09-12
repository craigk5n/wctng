<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller\Seo;

use App\Controller\Seo\SitemapController;
use App\Service\SeoEligibilityService;
use App\Service\TenantAwarePdoProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use WebCalendar\Core\Application\Service\ConfigService;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Repository\ConfigRepositoryInterface;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\Repository\UserRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventScope;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\UserPreference;

/**
 * What the sitemap hands to a crawler.
 *
 * This is the surface that gets entries indexed rather than merely reachable,
 * and the query behind it filters on cal_access alone -- so an entry waiting
 * for an administrator, one they refused, and one its owner deleted were all
 * submitted for indexing.
 */
final class SitemapControllerTest extends TestCase
{
    private const NOW = '2026-09-11T12:00:00+00:00';

    /** @var list<Event> */
    private array $events = [];
    /** @var list<list<string>|null> */
    private array $askedFor = [];

    private function controller(): SitemapController
    {
        $config = $this->createMock(ConfigRepositoryInterface::class);
        $config->method('get')->willReturnCallback(
            static fn(string $key): ?string => $key === 'ENABLE_SEO_PAGES' ? 'Y' : null,
        );
        $configService = new ConfigService($config);

        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findAll')->willReturn([new User('alice', 'Alice', 'Smith', 'alice@example.com', false, true)]);
        $users->method('findByLogin')->willReturn(new User('alice', 'Alice', 'Smith', 'alice@example.com', false, true));
        $users->method('getPreferences')->willReturn([new UserPreference('public_calendar_enabled', 'Y')]);

        $events = $this->createMock(EventRepositoryInterface::class);
        $events->method('findByDateRange')->willReturnCallback(
            function (mixed $range, EventScope $scope): array {
                $this->askedFor[] = $scope->users();

                return $this->events;
            },
        );

        // SQLite, which is also what tells the controller to skip its file cache.
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return new SitemapController(
            new SeoEligibilityService($configService, $users),
            $users,
            $events,
            new TenantAwarePdoProvider($pdo),
            new MockClock(self::NOW),
        );
    }

    private static function event(int $id, ?string $status): Event
    {
        return new Event(
            id: new EventId($id),
            uid: "e{$id}@x",
            name: "Event {$id}",
            description: '',
            location: '',
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
        $response = $this->controller()->sitemap();
        self::assertSame(200, $response->getStatusCode());
        $body = $response->getContent();
        self::assertIsString($body);

        return $body;
    }

    #[DataProvider('withheldStatuses')]
    public function testAnEntryThatWasNeverPublishedIsNotSubmittedForIndexing(string $status): void
    {
        $this->events = [self::event(1, $status), self::event(2, 'confirmed')];

        $xml = $this->render();

        $this->assertStringNotContainsString('/public/alice/event/1', $xml);
        $this->assertStringContainsString('/public/alice/event/2', $xml);
    }

    /** @return iterable<string, array{string}> */
    public static function withheldStatuses(): iterable
    {
        yield 'waiting for an administrator' => ['needs_approval'];
        yield 'refused' => ['rejected'];
        yield 'deleted by its owner' => ['cancelled'];
    }

    public function testAnEntryWithNoStatusAtAllIsStillSubmitted(): void
    {
        $this->events = [self::event(9, null)];

        $this->assertStringContainsString('/public/alice/event/9', $this->render());
    }

    public function testOnlyThisUsersEntriesAreAskedFor(): void
    {
        // An empty user list is not a narrower query -- it is every account's
        // entries, published under one person's name.
        $this->events = [self::event(1, 'confirmed')];

        $this->render();

        $this->assertSame([['alice']], $this->askedFor);
    }
}
