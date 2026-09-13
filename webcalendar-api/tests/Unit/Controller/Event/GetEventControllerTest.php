<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller\Event;

use App\Controller\Api\Event\GetEventController;
use App\Security\WebCalendarUser;
use App\Service\AccessPermissionRepository;
use App\Service\CoreServiceFactory;
use App\Service\EventVisibilityPolicy;
use App\Service\ExtParticipantRepository;
use App\Service\GeoRepository;
use App\Service\TenantAwarePdoProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use WebCalendar\Core\Domain\Entity\Category;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Infrastructure\Persistence\PdoCategoryRepository;

/**
 * Who may read one event by its id.
 *
 * The route had no check at all: any signed-in account could read any event
 * by number, private ones included, by counting through the integers. The
 * rule it answers to now is the one the listing already applies -- public
 * entries, your own, and whatever a grant opens -- so that a row visible in
 * the list cannot be refused when clicked, and nothing absent from the list
 * can be reached by guessing.
 */
final class GetEventControllerTest extends TestCase
{
    private \PDO $pdo;
    private CoreServiceFactory $factory;

    #[\Override]
    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $schemaPath = realpath(__DIR__ . '/../../../../vendor/craigk5n/webcalendar-core/src/Infrastructure/Persistence/sqlite-schema.sql');
        self::assertIsString($schemaPath);
        $schema = file_get_contents($schemaPath);
        self::assertIsString($schema);
        /** @var string[] $stmts */
        $stmts = preg_split('/;\s*\n/', (string) preg_replace('/--[^\n]*/', '', $schema)) ?? [];
        foreach ($stmts as $s) {
            if (trim($s) !== '') {
                try {
                    $this->pdo->exec(trim($s));
                } catch (\PDOException) {
                }
            }
        }

        $this->factory = new CoreServiceFactory($this->pdo, 'test_secret');
        $admin = self::coreUser('admin', admin: true);
        $this->factory->getUserService()->createUser($admin, $admin);
        foreach (['alice', 'bob'] as $login) {
            $this->factory->getUserService()->createUser(self::coreUser($login), $admin);
        }
    }

    private static function coreUser(string $login, bool $admin = false): User
    {
        return new User($login, 'F', 'L', "{$login}@x.com", $admin, true);
    }

    private function controller(): GetEventController
    {
        return new GetEventController(
            $this->factory->getEventService(),
            new PdoCategoryRepository($this->pdo),
            new GeoRepository($this->pdo),
            $this->factory->getEventRepository(),
            new ExtParticipantRepository($this->pdo),
            new EventVisibilityPolicy(new AccessPermissionRepository(new TenantAwarePdoProvider($this->pdo))),
        );
    }

    /** Creates an event owned by $owner and returns its id. */
    private function seed(string $owner, AccessLevel $access, string $name = 'Quarterly planning'): int
    {
        $uid = uniqid('get-', true) . '@webcalendar';
        $this->factory->getEventService()->createEvent(new Event(
            id: new EventId(0),
            uid: $uid,
            name: $name,
            description: 'the details',
            location: 'Room 1',
            start: new \DateTimeImmutable('2026-06-01 10:00:00'),
            duration: 60,
            createdBy: $owner,
            type: EventType::EVENT,
            access: $access,
        ), self::coreUser($owner));

        $stored = $this->factory->getEventRepository()->findByUid($uid);
        self::assertNotNull($stored);

        return $stored->id()->value();
    }

    private function grant(string $owner, string $grantee, bool $canView, bool $seeTimeOnly = false): void
    {
        $this->pdo->prepare(
            'INSERT INTO webcal_access_user (cal_login, cal_other_user, cal_can_view, cal_can_edit, cal_see_time_only)
             VALUES (?, ?, ?, 0, ?)',
        )->execute([$owner, $grantee, $canView ? 1 : 0, $seeTimeOnly ? 'Y' : 'N']);
    }

    private function get(int $id, ?string $as): JsonResponse
    {
        return ($this->controller())(
            $id,
            $as === null ? null : new WebCalendarUser(self::coreUser($as, admin: $as === 'admin'), null),
        );
    }

    /** @return array<string, mixed> */
    private static function payload(JsonResponse $r): array
    {
        /** @var array<string, mixed> $d */
        $d = json_decode((string) $r->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $d;
    }

    // ----------------------------------------------------------------- gate

    public function testAnAnonymousCallerGetsNothing(): void
    {
        self::assertSame(401, $this->get($this->seed('alice', AccessLevel::PUBLIC), null)->getStatusCode());
    }

    public function testAnEventThatIsNotThereIsStillNotFound(): void
    {
        self::assertSame(404, $this->get(999999, 'alice')->getStatusCode());
    }

    /** @return iterable<string, array{AccessLevel}> */
    public static function everyAccessLevel(): iterable
    {
        yield 'public' => [AccessLevel::PUBLIC];
        yield 'confidential' => [AccessLevel::CONFIDENTIAL];
        yield 'private' => [AccessLevel::PRIVATE];
    }

    #[DataProvider('everyAccessLevel')]
    public function testTheOwnerReadsTheirOwnEventAtAnyLevel(AccessLevel $access): void
    {
        $id = $this->seed('alice', $access);

        $body = self::payload($this->get($id, 'alice'));

        self::assertSame('Quarterly planning', $body['data']['title']);
        self::assertSame('the details', $body['data']['description']);
    }

    #[DataProvider('everyAccessLevel')]
    public function testAnAdministratorReadsAnyonesEventAtAnyLevel(AccessLevel $access): void
    {
        $id = $this->seed('alice', $access);

        $body = self::payload($this->get($id, 'admin'));

        self::assertSame('Quarterly planning', $body['data']['title']);
        self::assertSame('the details', $body['data']['description']);
    }

    public function testAnyoneSignedInReadsAPublicEvent(): void
    {
        // The listing hands bob alice's public events already, so refusing
        // this would refuse a row bob can see in front of him.
        $id = $this->seed('alice', AccessLevel::PUBLIC);

        self::assertSame(200, $this->get($id, 'bob')->getStatusCode());
    }

    /** @return iterable<string, array{AccessLevel}> */
    public static function levelsThatAreNotPublic(): iterable
    {
        yield 'confidential' => [AccessLevel::CONFIDENTIAL];
        yield 'private' => [AccessLevel::PRIVATE];
    }

    #[DataProvider('levelsThatAreNotPublic')]
    public function testAStrangerCannotReadWhatIsNotPublic(AccessLevel $access): void
    {
        $id = $this->seed('alice', $access);

        $response = $this->get($id, 'bob');

        // 404 rather than 403: the id is a small integer anybody can count
        // through, and a 403 would confirm which numbers are events.
        self::assertSame(404, $response->getStatusCode());
        self::assertStringNotContainsString('Quarterly planning', (string) $response->getContent());
        self::assertStringNotContainsString('the details', (string) $response->getContent());
    }

    #[DataProvider('levelsThatAreNotPublic')]
    public function testAGrantOpensWhatWouldOtherwiseBeRefused(AccessLevel $access): void
    {
        // The layers view already shows bob these when alice has granted it,
        // so the detail view has to agree with the list.
        $id = $this->seed('alice', $access);
        $this->grant('alice', 'bob', canView: true);

        self::assertSame(200, $this->get($id, 'bob')->getStatusCode());
    }

    public function testAGrantThatIsNotForViewingOpensNothing(): void
    {
        $id = $this->seed('alice', AccessLevel::PRIVATE);
        $this->grant('alice', 'bob', canView: false);

        self::assertSame(404, $this->get($id, 'bob')->getStatusCode());
    }

    public function testSomebodyElsesGrantIsNotBobs(): void
    {
        $id = $this->seed('alice', AccessLevel::PRIVATE);
        $this->grant('alice', 'carol', canView: true);

        self::assertSame(404, $this->get($id, 'bob')->getStatusCode());
    }

    // ------------------------------------------------ what the payload carries

    public function testTheGuestListCarriesEachLoginAndItsStatus(): void
    {
        // Nothing had asserted this shape, so dropping either key -- or the
        // loop that fills it -- went unnoticed. The masking test below turns
        // on the same list being emptied, which only means something if the
        // unmasked case is pinned.
        $id = $this->seed('alice', AccessLevel::PUBLIC);
        $this->factory->getEventRepository()->saveParticipants(new EventId($id), ['bob', 'carol']);

        $participants = self::payload($this->get($id, 'alice'))['data']['participants'];

        /** @var list<array{login: string, status: string}> $participants */
        self::assertCount(2, $participants);
        self::assertSame(['bob', 'carol'], array_column($participants, 'login'));
        foreach ($participants as $p) {
            self::assertArrayHasKey('status', $p, 'the status went missing from ' . $p['login']);
            self::assertNotSame('', $p['status']);
        }
    }

    public function testTheCategoriesAssignedToTheEventComeBackWithIt(): void
    {
        // This was hardcoded to [] once, which made the edit dialog show an
        // event as uncategorised however it was filed. Nothing pinned it.
        $id = $this->seed('alice', AccessLevel::PUBLIC);
        $this->factory->getCategoryService()->createCategory(
            new Category(7, 'alice', 'Planning', null, true),
            self::coreUser('alice'),
        );
        (new PdoCategoryRepository($this->pdo))->assignToEvent(new EventId($id), 'alice', [7]);

        self::assertSame([7], self::payload($this->get($id, 'alice'))['data']['categories']);
    }

    // -------------------------------------------------------------- masking

    public function testSeeTimeOnlyGetsTheHourAndNotTheSubject(): void
    {
        // The same masking the listing applies: busy, with nothing about what.
        $id = $this->seed('alice', AccessLevel::CONFIDENTIAL);
        $this->grant('alice', 'bob', canView: true, seeTimeOnly: true);

        $response = $this->get($id, 'bob');
        $body = self::payload($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Busy', $body['data']['title']);
        self::assertSame('', $body['data']['description']);
        self::assertSame('', $body['data']['location']);
        self::assertStringNotContainsString('Quarterly planning', (string) $response->getContent());
        self::assertStringNotContainsString('Room 1', (string) $response->getContent());

        // The hour is the point of the grant, so it survives.
        self::assertSame('20260601', $body['data']['start_date']);
    }

    public function testSeeTimeOnlyDoesNotNameWhoIsInTheMeeting(): void
    {
        $id = $this->seed('alice', AccessLevel::CONFIDENTIAL);
        $this->factory->getEventRepository()->saveParticipants(new EventId($id), ['carol']);
        $this->grant('alice', 'bob', canView: true, seeTimeOnly: true);

        $response = $this->get($id, 'bob');

        self::assertSame([], self::payload($response)['data']['participants']);
        self::assertStringNotContainsString('carol', (string) $response->getContent());
    }

    public function testAFullGrantStillShowsTheSubject(): void
    {
        $id = $this->seed('alice', AccessLevel::CONFIDENTIAL);
        $this->grant('alice', 'bob', canView: true, seeTimeOnly: false);

        $body = self::payload($this->get($id, 'bob'));

        self::assertSame('Quarterly planning', $body['data']['title']);
    }

    public function testTheOwnerIsNeverMaskedOutOfTheirOwnEvent(): void
    {
        $id = $this->seed('alice', AccessLevel::CONFIDENTIAL);
        $this->grant('alice', 'alice', canView: true, seeTimeOnly: true);

        self::assertSame('Quarterly planning', self::payload($this->get($id, 'alice'))['data']['title']);
    }
}
