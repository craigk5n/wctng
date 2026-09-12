<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller\Seo;

use App\Controller\Seo\EventIndexController;
use App\Service\CustomHtmlProvider;
use App\Service\SeoEligibilityService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use WebCalendar\Core\Application\Service\ConfigService;
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
 * The crawlable list of a user's events.
 *
 * The query behind it filters on cal_access and nothing else, so an entry
 * waiting for an administrator, one they refused, and one its owner deleted
 * were all listed here alongside the rest.
 */
final class EventIndexControllerTest extends TestCase
{
    private const NOW = '2026-09-11T12:00:00+00:00';

    /** @var list<Event> */
    private array $events = [];
    /** @var list<list<string>|null> */
    private array $askedFor = [];

    private function controller(): EventIndexController
    {
        $config = $this->createMock(ConfigRepositoryInterface::class);
        $config->method('get')->willReturnCallback(
            static fn(string $key): ?string => $key === 'ENABLE_SEO_PAGES' ? 'Y' : null,
        );
        $configService = new ConfigService($config);

        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findByLogin')->willReturn(new User('alice', 'Alice', 'Smith', 'alice@example.com', false, true));
        $users->method('getPreferences')->willReturn([new UserPreference('public_calendar_enabled', 'Y')]);

        $events = $this->createMock(EventRepositoryInterface::class);
        $events->method('findByDateRange')->willReturnCallback(
            function (mixed $range, mixed $user, mixed $access, ?array $users): array {
                $this->askedFor[] = $users;

                return $this->events;
            },
        );

        return new EventIndexController(
            new SeoEligibilityService($configService, $users),
            new UserService($users),
            $events,
            $configService,
            new CustomHtmlProvider($configService),
            null,
            new MockClock(self::NOW),
        );
    }

    private static function event(int $id, string $name, ?string $status): Event
    {
        return new Event(
            id: new EventId($id),
            uid: "e{$id}@x",
            name: $name,
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
        $response = $this->controller()->index('alice', Request::create('/public/alice/events'));
        self::assertSame(200, $response->getStatusCode());
        $body = $response->getContent();
        self::assertIsString($body);

        return $body;
    }

    #[DataProvider('withheldStatuses')]
    public function testAnEntryThatWasNeverPublishedIsNotListed(string $status): void
    {
        $this->events = [
            self::event(1, 'Held back', $status),
            self::event(2, 'Signed off', 'confirmed'),
        ];

        $html = $this->render();

        $this->assertStringNotContainsString('Held back', $html);
        $this->assertStringContainsString('Signed off', $html);
    }

    /** @return iterable<string, array{string}> */
    public static function withheldStatuses(): iterable
    {
        yield 'waiting for an administrator' => ['needs_approval'];
        yield 'refused' => ['rejected'];
        yield 'deleted by its owner' => ['cancelled'];
    }

    public function testAnEntryWithNoStatusAtAllIsStillListed(): void
    {
        // cal_status defaults to NULL, which is what nearly every row holds.
        $this->events = [self::event(1, 'Ordinary meeting', null)];

        $this->assertStringContainsString('Ordinary meeting', $this->render());
    }

    public function testTheCountMatchesWhatIsActuallyListed(): void
    {
        // The page numbers itself from this total; counting the hidden ones
        // would promise pages with nothing on them.
        $this->events = [
            self::event(1, 'Signed off', 'confirmed'),
            self::event(2, 'Deleted', 'cancelled'),
            self::event(3, 'Waiting', 'needs_approval'),
        ];

        $html = $this->render();

        $this->assertStringContainsString('Signed off', $html);
        $this->assertStringNotContainsString('Deleted', $html);
        $this->assertStringNotContainsString('Waiting', $html);
    }

    public function testOnlyThisUsersEntriesAreAskedFor(): void
    {
        // An empty user list is not a narrower query -- it is every account's
        // entries, listed under one person's name.
        $this->events = [self::event(1, 'Signed off', 'confirmed')];

        $this->render();

        $this->assertSame([['alice']], $this->askedFor);
    }
}
