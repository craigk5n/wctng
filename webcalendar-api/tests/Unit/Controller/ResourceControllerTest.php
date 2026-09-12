<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Api\ResourceController;
use App\Security\WebCalendarUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use WebCalendar\Core\Application\Service\ResourceService;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Infrastructure\Persistence\PdoResourceRepository;

/**
 * Bookable resources -- rooms, equipment -- and when they are free.
 *
 * Nothing executed a line of it. Four of the five routes take a body or a path
 * segment straight into a domain entity that validates its own arguments, and
 * the fifth answers the question the whole feature exists for: is this room
 * free on this day. An answer for the wrong day, returned under the requested
 * day's label, is a double booking nobody can see coming.
 */
final class ResourceControllerTest extends TestCase
{
    private \PDO $pdo;
    private ResourceService $resources;
    /** @var list<array{range: DateRange, users: list<string>|null}> */
    private array $queries = [];
    /** @var list<Event> */
    private array $events = [];

    #[\Override]
    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE webcal_nonuser_cals (
                cal_login VARCHAR(60) NOT NULL,
                cal_lastname VARCHAR(60) NULL,
                cal_firstname VARCHAR(60) NULL,
                cal_admin VARCHAR(60) NOT NULL,
                cal_is_public CHAR(1) DEFAULT \'N\' NOT NULL,
                cal_url VARCHAR(255) DEFAULT NULL,
                PRIMARY KEY (cal_login)
            )',
        );
        $this->resources = new ResourceService(new PdoResourceRepository($this->pdo));
        $this->queries = [];
        $this->events = [];
    }

    private function controller(): ResourceController
    {
        $events = $this->createMock(EventRepositoryInterface::class);
        $events->method('findByDateRange')->willReturnCallback(
            function (DateRange $range, ?User $user = null, ?string $access = null, ?array $users = null): array {
                $this->queries[] = ['range' => $range, 'users' => $users];

                return $this->events;
            },
        );

        return new ResourceController($this->resources, $events);
    }

    private static function admin(string $login = 'admin'): WebCalendarUser
    {
        return new WebCalendarUser(new User($login, 'Ad', 'Min', "{$login}@example.com", true, true), null);
    }

    private static function ordinaryUser(): WebCalendarUser
    {
        return new WebCalendarUser(new User('bob', 'Bob', 'Jones', 'bob@example.com', false, true), null);
    }

    private static function request(mixed $body): Request
    {
        $content = \is_string($body) ? $body : json_encode($body, \JSON_THROW_ON_ERROR);

        return Request::create('/api/v2/admin/resources', 'POST', [], [], [], [], $content);
    }

    private static function availabilityRequest(string $query = ''): Request
    {
        return Request::create('/api/v2/resources/projector/availability?' . $query);
    }

    /** @return array<string, mixed> */
    private static function payload(Response $response): array
    {
        $body = $response->getContent();
        self::assertIsString($body);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function storedRow(string $login): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM webcal_nonuser_cals WHERE cal_login = :login');
        $stmt->execute(['login' => $login]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return \is_array($row) ? $row : null;
    }

    private function seed(string $login, string $name, string $admin, bool $public = false, ?string $url = null): void
    {
        $this->pdo->prepare(
            'INSERT INTO webcal_nonuser_cals (cal_login, cal_lastname, cal_admin, cal_is_public, cal_url)
             VALUES (:login, :name, :admin, :public, :url)',
        )->execute([
            'login' => $login,
            'name' => $name,
            'admin' => $admin,
            'public' => $public ? 'Y' : 'N',
            'url' => $url,
        ]);
    }

    // ------------------------------------------------------------ who may act

    #[DataProvider('administrativeCalls')]
    public function testManagingResourcesNeedsAnAdministrator(\Closure $call, ?WebCalendarUser $caller): void
    {
        $this->assertSame(403, $call($this->controller(), $caller)->getStatusCode());
        $this->assertNull($this->storedRow('projector'), 'nothing may be written by a caller who was refused');
    }

    /** @return iterable<string, array{\Closure, WebCalendarUser|null}> */
    public static function administrativeCalls(): iterable
    {
        $calls = [
            'list' => static fn(ResourceController $c, ?WebCalendarUser $u): Response => $c->list($u),
            'create' => static fn(ResourceController $c, ?WebCalendarUser $u): Response
                => $c->create(self::request(['login' => 'projector', 'name' => 'Projector']), $u),
            'update' => static fn(ResourceController $c, ?WebCalendarUser $u): Response
                => $c->update('projector', self::request(['name' => 'New']), $u),
            'delete' => static fn(ResourceController $c, ?WebCalendarUser $u): Response => $c->delete('projector', $u),
        ];

        foreach ($calls as $name => $call) {
            yield "{$name}, anonymous" => [$call, null];
            yield "{$name}, not an admin" => [$call, self::ordinaryUser()];
        }
    }

    public function testAvailabilityIsOpenToAnySignedInUser(): void
    {
        // Anyone who can book a room has to be able to see when it is free.
        $anonymous = ($this->controller())->availability('projector', self::availabilityRequest('date=2026-10-01'), null);
        $this->assertSame(401, $anonymous->getStatusCode());

        $signedIn = ($this->controller())->availability('projector', self::availabilityRequest('date=2026-10-01'), self::ordinaryUser());
        $this->assertSame(200, $signedIn->getStatusCode());
    }

    // ------------------------------------------------------------------- list

    public function testListDescribesEveryResourceInFull(): void
    {
        $this->seed('projector', 'Projector', 'admin', true, 'https://example.com/p');
        $this->seed('room-a', 'Room A', 'alice');

        $items = self::payload($this->controller()->list(self::admin()))['data'];

        $this->assertCount(2, $items);
        $this->assertSame(
            ['login' => 'projector', 'name' => 'Projector', 'admin' => 'admin', 'is_public' => true, 'url' => 'https://example.com/p'],
            $items[0],
        );
        $this->assertSame(
            ['login' => 'room-a', 'name' => 'Room A', 'admin' => 'alice', 'is_public' => false, 'url' => null],
            $items[1],
        );
    }

    // ----------------------------------------------------------------- create

    public function testCreateStoresTheResource(): void
    {
        $response = $this->controller()->create(
            self::request(['login' => 'projector', 'name' => 'Projector', 'admin' => 'alice', 'is_public' => true, 'url' => 'https://x/p']),
            self::admin(),
        );

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(
            ['login' => 'projector', 'name' => 'Projector', 'admin' => 'alice', 'is_public' => true, 'url' => 'https://x/p'],
            self::payload($response)['data'],
        );

        $row = $this->storedRow('projector');
        $this->assertNotNull($row);
        $this->assertSame('Projector', $row['cal_lastname']);
        $this->assertSame('alice', $row['cal_admin']);
        $this->assertSame('Y', $row['cal_is_public']);
    }

    public function testCreateDefaultsTheAdministratorToWhoeverMadeIt(): void
    {
        $response = $this->controller()->create(
            self::request(['login' => 'projector', 'name' => 'Projector']),
            self::admin('sysop'),
        );

        $data = self::payload($response)['data'];
        $this->assertSame('sysop', $data['admin']);
        $this->assertFalse($data['is_public']);
        $this->assertNull($data['url']);
        $this->assertSame('N', $this->storedRow('projector')['cal_is_public']);
    }

    #[DataProvider('rejectedCreateBodies')]
    public function testCreateRefusesABodyItCannotBuildAResourceFrom(mixed $body): void
    {
        $response = $this->controller()->create(self::request($body), self::admin());

        $this->assertSame(400, $response->getStatusCode());
        $this->assertNull($this->storedRow('projector'));
    }

    /** @return iterable<string, array{mixed}> */
    public static function rejectedCreateBodies(): iterable
    {
        yield 'no login' => [['name' => 'Projector']];
        yield 'no name' => [['login' => 'projector']];
        yield 'empty login' => [['login' => '', 'name' => 'Projector']];
        yield 'empty name' => [['login' => 'projector', 'name' => '']];
        // Resource validates its own arguments and throws on either of these,
        // which the route never caught -- a body of spaces was a 500.
        yield 'login of spaces' => [['login' => '   ', 'name' => 'Projector']];
        yield 'name of spaces' => [['login' => 'projector', 'name' => "\t\n "]];
        yield 'login is a number' => [['login' => 42, 'name' => 'Projector']];
        yield 'name is an array' => [['login' => 'projector', 'name' => ['Projector']]];
        yield 'not an object' => ['"just a string"'];
        yield 'not json at all' => ['<html>'];
        yield 'empty body' => [''];
    }

    /**
     * save() looks the login up and turns into an UPDATE when it finds one, so
     * the create route silently rewrote an existing room's name, owner and
     * visibility -- and answered 201 as though it had made a new one.
     */
    public function testCreateWillNotQuietlyRewriteAnExistingResource(): void
    {
        $this->seed('projector', 'Ceiling projector', 'alice', true, 'https://x/p');

        $response = $this->controller()->create(
            self::request(['login' => 'projector', 'name' => 'Mine now', 'admin' => 'mallory']),
            self::admin(),
        );

        $this->assertSame(409, $response->getStatusCode());
        $row = $this->storedRow('projector');
        $this->assertSame('Ceiling projector', $row['cal_lastname']);
        $this->assertSame('alice', $row['cal_admin']);
        $this->assertSame('Y', $row['cal_is_public']);
    }

    #[DataProvider('wronglyTypedOptionalFields')]
    public function testCreateFallsBackWhenAnOptionalFieldIsTheWrongType(array $body, array $expected): void
    {
        $response = $this->controller()->create(self::request($body), self::admin('sysop'));

        $this->assertSame(201, $response->getStatusCode());
        $data = self::payload($response)['data'];
        $this->assertSame($expected['admin'], $data['admin']);
        $this->assertSame($expected['is_public'], $data['is_public']);
        $this->assertSame($expected['url'], $data['url']);
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>}> */
    public static function wronglyTypedOptionalFields(): iterable
    {
        $base = ['login' => 'projector', 'name' => 'Projector'];

        yield 'admin is a number' => [
            $base + ['admin' => 7],
            ['admin' => 'sysop', 'is_public' => false, 'url' => null],
        ];
        yield 'is_public is a string' => [
            $base + ['is_public' => 'yes'],
            ['admin' => 'sysop', 'is_public' => false, 'url' => null],
        ];
        yield 'url is an array' => [
            $base + ['url' => ['https://x']],
            ['admin' => 'sysop', 'is_public' => false, 'url' => null],
        ];
    }

    // ----------------------------------------------------------------- update

    public function testUpdateReportsAnUnknownResourceAsMissing(): void
    {
        $this->assertSame(404, $this->controller()->update('nope', self::request(['name' => 'X']), self::admin())->getStatusCode());
    }

    public function testUpdateChangesOnlyWhatWasSent(): void
    {
        $this->seed('projector', 'Projector', 'alice', true, 'https://x/p');

        $response = $this->controller()->update('projector', self::request(['name' => 'Big projector']), self::admin());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            ['login' => 'projector', 'name' => 'Big projector', 'admin' => 'alice', 'is_public' => true, 'url' => 'https://x/p'],
            self::payload($response)['data'],
        );
        $this->assertSame('Big projector', $this->storedRow('projector')['cal_lastname']);
        $this->assertSame('alice', $this->storedRow('projector')['cal_admin']);
    }

    public function testUpdateCanClearTheVisibilityAndTheUrl(): void
    {
        $this->seed('projector', 'Projector', 'alice', true, 'https://x/p');

        $this->controller()->update('projector', self::request(['is_public' => false, 'url' => null]), self::admin());

        $row = $this->storedRow('projector');
        $this->assertSame('N', $row['cal_is_public']);
        $this->assertNull($row['cal_url']);
    }

    public function testUpdateCanSetTheUrl(): void
    {
        $this->seed('projector', 'Projector', 'alice');

        $response = $this->controller()->update('projector', self::request(['url' => 'https://wiki/projector']), self::admin());

        $this->assertSame('https://wiki/projector', self::payload($response)['data']['url']);
        $this->assertSame('https://wiki/projector', $this->storedRow('projector')['cal_url']);
    }

    public function testUpdateKeepsTheUrlWhenTheBodyDoesNotMentionIt(): void
    {
        $this->seed('projector', 'Projector', 'alice', false, 'https://wiki/projector');

        $this->controller()->update('projector', self::request(['name' => 'Big projector']), self::admin());

        $this->assertSame('https://wiki/projector', $this->storedRow('projector')['cal_url']);
    }

    #[DataProvider('rejectedUpdateBodies')]
    public function testUpdateRefusesAChangeThatWouldNotBeAResource(mixed $body): void
    {
        $this->seed('projector', 'Projector', 'alice');

        $response = $this->controller()->update('projector', self::request($body), self::admin());

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Projector', $this->storedRow('projector')['cal_lastname']);
        $this->assertSame('alice', $this->storedRow('projector')['cal_admin']);
    }

    /** @return iterable<string, array{mixed}> */
    public static function rejectedUpdateBodies(): iterable
    {
        yield 'empty name' => [['name' => '']];
        yield 'name of spaces' => [['name' => '  ']];
    }

    public function testUpdateKeepsTheNameWhenWhatArrivedIsNotOne(): void
    {
        // Every field here is optional, so one of the wrong type falls back to
        // what is stored rather than refusing the rest of the change.
        $this->seed('projector', 'Projector', 'alice');

        $response = $this->controller()->update('projector', self::request(['name' => 7, 'admin' => 'bob']), self::admin());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Projector', self::payload($response)['data']['name']);
        $this->assertSame('bob', $this->storedRow('projector')['cal_admin']);
    }

    // ----------------------------------------------------------------- delete

    public function testDeleteRemovesTheResource(): void
    {
        $this->seed('projector', 'Projector', 'alice');

        $response = $this->controller()->delete('projector', self::admin());

        $this->assertSame(204, $response->getStatusCode());
        $this->assertNull($this->storedRow('projector'));
    }

    // ----------------------------------------------------------- availability

    #[DataProvider('unusableDates')]
    public function testAvailabilityRefusesADateItCannotAnswerFor(string $query, string $message): void
    {
        $response = $this->controller()->availability('projector', self::availabilityRequest($query), self::ordinaryUser());

        $this->assertSame(400, $response->getStatusCode());
        // A caller who sent no date and a caller who sent a bad one need
        // different things said to them; both paths end in a 400, so the
        // status alone does not tell them apart.
        $this->assertSame($message, self::payload($response)['error']['message']);
        $this->assertSame([], $this->queries, 'nothing should be looked up for a date that was refused');
    }

    /** @return iterable<string, array{string, string}> */
    public static function unusableDates(): iterable
    {
        $missing = 'Missing required query param: date (YYYY-MM-DD)';
        $invalid = 'Invalid date format';

        yield 'no date at all' => ['', $missing];
        yield 'empty date' => ['date=', $missing];
        yield 'words' => ['date=tomorrow', $invalid];
        yield 'the wrong shape' => ['date=01/10/2026', $invalid];
        // createFromFormat rolls these forward instead of refusing them, and
        // the reply still carries the date that was asked for: the caller is
        // shown another day's bookings under today's label.
        yield 'the 31st of February' => ['date=2026-02-31', $invalid];
        yield 'the 13th month' => ['date=2026-13-45', $invalid];
    }

    public function testAvailabilityAsksAboutTheDayItWasGiven(): void
    {
        $this->controller()->availability('projector', self::availabilityRequest('date=2026-10-01'), self::ordinaryUser());

        $this->assertCount(1, $this->queries);
        $this->assertSame(['projector'], $this->queries[0]['users']);
        $this->assertSame('2026-10-01 00:00:00', $this->queries[0]['range']->startDate()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-01 23:59:59', $this->queries[0]['range']->endDate()->format('Y-m-d H:i:s'));
    }

    public function testAvailabilityListsWhenTheResourceIsTakenAndForHowLong(): void
    {
        $this->events = [
            self::event('Sprint review', '2026-10-01 09:00:00', 90),
            self::event('All hands', '2026-10-01 14:00:00', 60),
        ];

        $data = self::payload(
            $this->controller()->availability('projector', self::availabilityRequest('date=2026-10-01'), self::ordinaryUser()),
        )['data'];

        $this->assertSame('projector', $data['resource']);
        $this->assertSame('2026-10-01', $data['date']);
        $this->assertSame([
            ['title' => 'Sprint review', 'start' => '09:00', 'end' => '10:30'],
            ['title' => 'All hands', 'start' => '14:00', 'end' => '15:00'],
        ], $data['busy']);
    }

    public function testAFreeResourceHasNothingBusy(): void
    {
        $data = self::payload(
            $this->controller()->availability('projector', self::availabilityRequest('date=2026-10-01'), self::ordinaryUser()),
        )['data'];

        $this->assertSame([], $data['busy']);
    }

    private static function event(string $name, string $start, int $duration): Event
    {
        return new Event(
            id: new EventId(1),
            uid: 'e@x',
            name: $name,
            description: '',
            location: '',
            start: new \DateTimeImmutable($start),
            duration: $duration,
            createdBy: 'alice',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
        );
    }
}
