<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Api\LocationController;
use App\Security\WebCalendarUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Repository\UserRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\UserPreference;

/**
 * Where somebody is working on a given day.
 *
 * Nothing executed a line of it. The date is not a parameter to a query here --
 * it is half of a preference key, concatenated straight onto "location_" and
 * written to a column that is sixty characters wide and part of a primary key.
 * Nothing checked it was a date, or a string, or short enough to fit.
 */
final class LocationControllerTest extends TestCase
{
    private const NOW = '2026-09-11T08:00:00+00:00';

    /** @var list<array{login: string, key: string, value: string}> */
    private array $saved = [];
    /** @var list<UserPreference> */
    private array $preferences = [];

    private function controller(): LocationController
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('getPreferences')->willReturnCallback(fn(): array => $this->preferences);
        $users->method('savePreference')->willReturnCallback(
            function (string $login, UserPreference $preference): void {
                $this->saved[] = ['login' => $login, 'key' => $preference->key(), 'value' => $preference->value()];
            },
        );

        return new LocationController($users, new MockClock(self::NOW));
    }

    private static function user(string $login = 'alice', bool $admin = false): WebCalendarUser
    {
        return new WebCalendarUser(new User($login, ucfirst($login), 'Smith', "{$login}@example.com", $admin, true), null);
    }

    private static function getRequest(string $query = ''): Request
    {
        return Request::create('/api/v2/users/alice/location?' . $query);
    }

    private static function putRequest(mixed $body): Request
    {
        $content = \is_string($body) ? $body : json_encode($body, \JSON_THROW_ON_ERROR);

        return Request::create('/api/v2/users/alice/location', 'PUT', [], [], [], [], $content);
    }

    /** @return array<string, mixed> */
    private static function payload(JsonResponse $response): array
    {
        $body = $response->getContent();
        self::assertIsString($body);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    // ------------------------------------------------------------------ reading

    public function testReadingNeedsASession(): void
    {
        $this->assertSame(401, $this->controller()->getLocation('alice', self::getRequest(), null)->getStatusCode());
    }

    public function testWithNoDateItAnswersForToday(): void
    {
        $this->preferences = [
            new UserPreference('location_2026-09-10', 'remote'),
            new UserPreference('location_2026-09-11', 'traveling'),
        ];

        $data = self::payload($this->controller()->getLocation('alice', self::getRequest(), self::user()))['data'];

        $this->assertSame('2026-09-11', $data['date']);
        $this->assertSame('traveling', $data['location']);
        $this->assertSame('alice', $data['user']);
    }

    public function testItAnswersForTheDayThatWasAskedAbout(): void
    {
        $this->preferences = [
            new UserPreference('location_2026-09-10', 'remote'),
            new UserPreference('location_2026-09-11', 'traveling'),
        ];

        $data = self::payload(
            $this->controller()->getLocation('alice', self::getRequest('date=2026-09-10'), self::user()),
        )['data'];

        $this->assertSame('2026-09-10', $data['date']);
        $this->assertSame('remote', $data['location']);
    }

    public function testADayNobodySaidAnythingAboutIsTheOffice(): void
    {
        $this->preferences = [new UserPreference('location_2026-09-10', 'remote')];

        $data = self::payload(
            $this->controller()->getLocation('alice', self::getRequest('date=2026-09-12'), self::user()),
        )['data'];

        $this->assertSame('office', $data['location']);
    }

    public function testOtherPreferencesAreNotMistakenForALocation(): void
    {
        $this->preferences = [
            new UserPreference('public_calendar_enabled', 'Y'),
            new UserPreference('location_2026-09-11', 'remote'),
        ];

        $data = self::payload($this->controller()->getLocation('alice', self::getRequest(), self::user()))['data'];

        $this->assertSame('remote', $data['location']);
    }

    // ------------------------------------------------------------------ writing

    public function testWritingNeedsASession(): void
    {
        $response = $this->controller()->setLocation('alice', self::putRequest(['location' => 'remote']), null);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame([], $this->saved);
    }

    public function testYouMayOnlySetYourOwn(): void
    {
        $response = $this->controller()->setLocation('alice', self::putRequest(['location' => 'remote']), self::user('bob'));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame([], $this->saved);
    }

    public function testAnAdministratorMaySetSomebodyElses(): void
    {
        $response = $this->controller()->setLocation(
            'alice',
            self::putRequest(['date' => '2026-09-11', 'location' => 'remote']),
            self::user('sysop', admin: true),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([['login' => 'alice', 'key' => 'location_2026-09-11', 'value' => 'remote']], $this->saved);
    }

    public function testSettingYourOwnStoresItUnderThatDay(): void
    {
        $response = $this->controller()->setLocation(
            'alice',
            self::putRequest(['date' => '2026-12-24', 'location' => 'traveling']),
            self::user('alice'),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([['login' => 'alice', 'key' => 'location_2026-12-24', 'value' => 'traveling']], $this->saved);
        $this->assertSame(
            ['user' => 'alice', 'date' => '2026-12-24', 'location' => 'traveling'],
            self::payload($response)['data'],
        );
    }

    public function testWithNoDateItWritesTodays(): void
    {
        $this->controller()->setLocation('alice', self::putRequest(['location' => 'remote']), self::user());

        $this->assertSame('location_2026-09-11', $this->saved[0]['key']);
    }

    public function testWithNoLocationItWritesTheOffice(): void
    {
        $this->controller()->setLocation('alice', self::putRequest(['date' => '2026-09-11']), self::user());

        $this->assertSame('office', $this->saved[0]['value']);
    }

    #[DataProvider('everyPlaceOneCanBe')]
    public function testEachOfTheThreePlacesIsAccepted(string $location): void
    {
        $response = $this->controller()->setLocation(
            'alice',
            self::putRequest(['date' => '2026-09-11', 'location' => $location]),
            self::user(),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($location, $this->saved[0]['value']);
    }

    /** @return iterable<string, array{string}> */
    public static function everyPlaceOneCanBe(): iterable
    {
        yield 'office' => ['office'];
        yield 'remote' => ['remote'];
        yield 'traveling' => ['traveling'];
    }

    #[DataProvider('placesOneCannotBe')]
    public function testAnythingElseIsRefused(mixed $location): void
    {
        $response = $this->controller()->setLocation(
            'alice',
            self::putRequest(['date' => '2026-09-11', 'location' => $location]),
            self::user(),
        );

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $this->saved);
        $this->assertSame(
            'Location must be: office, remote, traveling',
            self::payload($response)['error']['message'],
            'naming the three is the only useful thing a 400 here can say',
        );
    }

    /** @return iterable<string, array{mixed}> */
    public static function placesOneCannotBe(): iterable
    {
        yield 'somewhere else' => ['pub'];
        yield 'the wrong case' => ['Office'];
        yield 'empty' => [''];
        yield 'a number' => [7];
        yield 'an array' => [['office']];
    }

    // --------------------------------------------------------------- the date

    /**
     * The date is half of a preference key, and cal_setting is a VARCHAR(60)
     * inside a primary key. Nothing checked what arrived: a long enough one
     * overflowed the column and took the request down with it, and an array
     * became the literal key "location_Array".
     */
    #[DataProvider('datesThatAreNotDates')]
    public function testWritingRefusesADateThatIsNotOne(mixed $date): void
    {
        $response = $this->controller()->setLocation(
            'alice',
            self::putRequest(['date' => $date, 'location' => 'remote']),
            self::user(),
        );

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $this->saved);
    }

    #[DataProvider('datesThatAreNotDates')]
    public function testReadingRefusesADateThatIsNotOne(mixed $date): void
    {
        if (!\is_string($date)) {
            $this->markTestSkipped('the query string only ever carries strings');
        }

        $response = $this->controller()->getLocation('alice', self::getRequest('date=' . urlencode($date)), self::user());

        $this->assertSame(400, $response->getStatusCode());
    }

    /** @return iterable<string, array{mixed}> */
    public static function datesThatAreNotDates(): iterable
    {
        yield 'words' => ['tomorrow'];
        yield 'the wrong shape' => ['11/09/2026'];
        yield 'the 31st of February' => ['2026-02-31'];
        yield 'the 13th month' => ['2026-13-01'];
        yield 'longer than the column' => [str_repeat('9', 80)];
        yield 'a number' => [20260911];
        yield 'an array' => [['2026-09-11']];
    }

    public function testAnEmptyDateMeansToday(): void
    {
        // `?date=` and a body of `{"date": ""}` are a client that did not fill
        // the field in, which is what the default is for.
        $this->controller()->setLocation('alice', self::putRequest(['date' => '', 'location' => 'remote']), self::user());
        $this->assertSame('location_2026-09-11', $this->saved[0]['key']);

        $data = self::payload($this->controller()->getLocation('alice', self::getRequest('date='), self::user()))['data'];
        $this->assertSame('2026-09-11', $data['date']);
    }

    public function testTheKeyStaysInsideTheColumn(): void
    {
        // cal_setting is a VARCHAR(60) and part of the primary key, so a key
        // that does not fit is not truncated quietly -- MySQL refuses the row.
        $this->controller()->setLocation(
            'alice',
            self::putRequest(['date' => '2026-09-11', 'location' => 'remote']),
            self::user(),
        );

        $this->assertLessThanOrEqual(60, \strlen($this->saved[0]['key']));
    }
}
