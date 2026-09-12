<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Api\Event\RestoreEventController;
use App\Security\WebCalendarUser;
use App\Service\EventUserPolicy;
use App\Service\MercurePublisher;
use App\Service\TenantAwarePdoProvider;
use App\Tenant\TenantContext;
use App\Tests\Unit\EventSubscriber\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use WebCalendar\Core\Application\Service\ActivityLogService;
use WebCalendar\Core\Application\Service\EventService;
use WebCalendar\Core\Domain\Entity\ActivityLogEntry;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Repository\ActivityLogRepositoryInterface;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\Repository\UserRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\UserPreference;

/**
 * Undoing a deletion.
 *
 * Nothing executed a line of it. `previous_status` is a value the server hands
 * the client in the delete response and then takes back on trust here, which
 * makes it the client's to choose -- and what it chose went straight into
 * cal_status, the column that now decides whether an entry is published at all.
 */
final class RestoreEventControllerTest extends TestCase
{
    private \PDO $pdo;
    private ?Event $event = null;
    private bool $ownerNeedsApproval = false;
    /** @var list<array{login: string, status: string}> */
    private array $participantWrites = [];
    private RecordingLogger $logger;
    private bool $mercureFails = false;
    private bool $activityLogFails = false;
    /** @var list<array{topics: list<string>, data: mixed}> */
    private array $published = [];
    /** @var list<string> */
    private array $loggedActivity = [];

    #[\Override]
    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE webcal_entry (cal_id INTEGER PRIMARY KEY, cal_status VARCHAR(20) DEFAULT NULL)');
        $this->pdo->exec("INSERT INTO webcal_entry (cal_id, cal_status) VALUES (7, 'cancelled')");

        $this->logger = new RecordingLogger();
        $this->participantWrites = [];
        $this->mercureFails = false;
        $this->activityLogFails = false;
        $this->published = [];
        $this->loggedActivity = [];
        $this->ownerNeedsApproval = false;
        $this->event = self::event('cancelled');
    }

    private static function event(?string $status, string $createdBy = 'alice'): Event
    {
        return new Event(
            id: new EventId(7),
            uid: 'e7@x',
            name: 'Quarterly review',
            description: '',
            location: '',
            start: new \DateTimeImmutable('2026-10-01 10:00:00'),
            duration: 60,
            createdBy: $createdBy,
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
            status: $status,
        );
    }

    private function controller(): RestoreEventController
    {
        $events = $this->createMock(EventRepositoryInterface::class);
        $events->method('findById')->willReturnCallback(fn(): ?Event => $this->event);
        $events->method('updateParticipantStatus')->willReturnCallback(
            function (EventId $id, string $login, string $status): void {
                $this->participantWrites[] = ['login' => $login, 'status' => $status];
            },
        );

        // Keyed on the login asked about, so a test can tell whose settings
        // the controller actually consulted.
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('getPreferences')->willReturnCallback(
            fn(string $login): array => $this->ownerNeedsApproval && $login === 'alice'
                ? [new UserPreference('require_event_approval', 'Y')]
                : [],
        );

        $hub = $this->createMock(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update): string {
            if ($this->mercureFails) {
                throw new \RuntimeException('hub unreachable');
            }

            $this->published[] = [
                'topics' => array_values($update->getTopics()),
                'data' => json_decode($update->getData(), true),
            ];

            return 'id';
        });

        $logRepo = $this->createMock(ActivityLogRepositoryInterface::class);
        $logRepo->method('save')->willReturnCallback(function (ActivityLogEntry $entry): void {
            if ($this->activityLogFails) {
                throw new \RuntimeException('log table missing');
            }

            $this->loggedActivity[] = $entry->text();
        });

        return new RestoreEventController(
            new EventService($events, $users),
            $events,
            new MercurePublisher($hub, new TenantContext()),
            new ActivityLogService($logRepo),
            new TenantAwarePdoProvider($this->pdo),
            new EventUserPolicy($users),
            $this->logger,
        );
    }

    private static function user(string $login, bool $admin = false): WebCalendarUser
    {
        return new WebCalendarUser(new User($login, ucfirst($login), 'S', "{$login}@x.com", $admin, true), null);
    }

    private static function request(mixed $body = []): Request
    {
        $content = \is_string($body) ? $body : json_encode($body, \JSON_THROW_ON_ERROR);

        return Request::create('/api/v2/events/7/restore', 'POST', [], [], [], [], $content);
    }

    private function storedStatus(): ?string
    {
        $value = $this->pdo->query('SELECT cal_status FROM webcal_entry WHERE cal_id = 7')?->fetchColumn();

        return \is_string($value) ? $value : null;
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

    // ------------------------------------------------------------------ guards

    public function testItNeedsASession(): void
    {
        $this->assertSame(401, ($this->controller())(7, self::request(), null)->getStatusCode());
        $this->assertSame('cancelled', $this->storedStatus());
    }

    public function testAnUnknownEventIsNotFound(): void
    {
        $this->event = null;

        $this->assertSame(404, ($this->controller())(7, self::request(), self::user('alice'))->getStatusCode());
    }

    public function testAnEventThatWasNotCancelledCannotBeRestored(): void
    {
        $this->event = self::event('confirmed');

        $response = ($this->controller())(7, self::request(), self::user('alice'));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Event is not cancelled', self::payload($response)['error']['message']);
    }

    // ------------------------------------------------------- restoring an event

    public function testTheOrganiserCanUndoTheirOwnDeletion(): void
    {
        $response = ($this->controller())(7, self::request(), self::user('alice'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue(self::payload($response)['data']['restored']);
        $this->assertNull($this->storedStatus());
    }

    public function testAnAdministratorCanUndoSomebodyElsesDeletion(): void
    {
        $response = ($this->controller())(7, self::request(), self::user('sysop', admin: true));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($this->storedStatus());
    }

    /**
     * previous_status is handed to the client by the delete response and sent
     * straight back here, so it is whatever the client says it is. Written into
     * cal_status unchecked, it let an organiser bring an entry back as anything
     * -- including approved, which is not theirs to decide.
     */
    #[DataProvider('statusesAClientMightSend')]
    public function testWhatTheClientSendsBackDoesNotDecideTheStatus(mixed $previousStatus): void
    {
        $response = ($this->controller())(
            7,
            self::request(['previous_status' => $previousStatus]),
            self::user('alice'),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($this->storedStatus());
    }

    /** @return iterable<string, array{mixed}> */
    public static function statusesAClientMightSend(): iterable
    {
        yield 'approved' => ['confirmed'];
        yield 'still waiting' => ['needs_approval'];
        yield 'tentative' => ['tentative'];
        yield 'refused' => ['rejected'];
        yield 'something made up' => ['whatever'];
        // cal_status is a VARCHAR(20); this one does not fit in it.
        yield 'longer than the column' => [str_repeat('x', 80)];
        yield 'a number' => [7];
        yield 'an array' => [['confirmed']];
    }

    /**
     * Restoring an entry publishes it again, so it goes through the gate that
     * creating one does. Without that, deleting and undoing was a way to take
     * an entry the administrator had not seen yet and bring it back approved.
     */
    public function testAnEntryFromSomebodyWhoNeedsApprovalComesBackWaitingForIt(): void
    {
        $this->ownerNeedsApproval = true;

        ($this->controller())(7, self::request(['previous_status' => 'confirmed']), self::user('alice'));

        $this->assertSame('needs_approval', $this->storedStatus());
    }

    public function testTheOrganisersApprovalSettingIsTheOneThatCounts(): void
    {
        // An administrator undoing somebody else's deletion does not carry
        // their own exemption across to that person's calendar.
        $this->ownerNeedsApproval = true;
        $this->event = self::event('cancelled', createdBy: 'alice');

        ($this->controller())(7, self::request(), self::user('sysop', admin: true));

        $this->assertSame('needs_approval', $this->storedStatus());
    }

    public function testTheRestoreIsWrittenToTheActivityLog(): void
    {
        ($this->controller())(7, self::request(), self::user('alice'));

        $this->assertSame([], $this->logger->records, 'nothing should have gone wrong');
        // Which entry came back is the whole value of the line.
        $this->assertSame(['Restored event: Quarterly review'], $this->loggedActivity);
    }

    public function testRestoringAnEventTellsTheOpenTabsWhatHappened(): void
    {
        // The browsers watching this calendar redraw from the payload; without
        // the action in it they are told something changed and not what.
        ($this->controller())(7, self::request(), self::user('alice'));

        $this->assertCount(1, $this->published);
        $this->assertSame(
            ['type' => 'event.updated', 'event' => ['action' => 'restored']],
            $this->published[0]['data'],
        );
    }

    public function testRestoringAnEventDoesNotAlsoTouchTheCallersSeat(): void
    {
        // The two halves of this route are exclusive: falling out of the
        // organiser branch into the participant one would rewrite a seat
        // nobody asked about.
        ($this->controller())(7, self::request(), self::user('alice'));

        $this->assertSame([], $this->participantWrites);
    }

    #[DataProvider('thingsThatCanFailQuietly')]
    public function testSomethingElseFailingDoesNotLoseTheRestore(string $which, string $message, string $expected): void
    {
        // The row has already changed by the time these run; a hub that is
        // down must not turn a completed restore into an error.
        $this->{$which} = true;

        $response = ($this->controller())(7, self::request(), self::user('alice'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($this->storedStatus());
        $this->assertSame([$message], array_column($this->logger->records, 'message'));
        // Without what actually went wrong, the line says only that something did.
        $this->assertSame(['exception' => $expected], $this->logger->records[0]['context']);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function thingsThatCanFailQuietly(): iterable
    {
        yield 'the hub is down' => ['mercureFails', 'mercure->publishEventUpdated() failed', 'hub unreachable'];
        yield 'the log table is missing' => ['activityLogFails', 'activityLogService->log() failed', 'log table missing'];
    }

    // -------------------------------------------------- restoring a participant

    public function testSomebodyElseOnTheEventGetsTheirSeatBack(): void
    {
        $response = ($this->controller())(7, self::request(), self::user('bob'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue(self::payload($response)['data']['restored']);
        $this->assertSame([['login' => 'bob', 'status' => 'A']], $this->participantWrites);
        $this->assertSame('cancelled', $this->storedStatus(), 'a participant does not un-cancel the event');
    }

    public function testRestoringASeatTellsTheOpenTabsWhoseItWas(): void
    {
        ($this->controller())(7, self::request(), self::user('bob'));

        $this->assertCount(1, $this->published);
        $this->assertSame(
            [
                'type' => 'participant.changed',
                'eventId' => 7,
                'data' => ['action' => 'restored', 'login' => 'bob'],
            ],
            $this->published[0]['data'],
        );
    }

    public function testAHubThatIsDownDoesNotLoseAParticipantsSeat(): void
    {
        $this->mercureFails = true;

        $response = ($this->controller())(7, self::request(), self::user('bob'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([['login' => 'bob', 'status' => 'A']], $this->participantWrites);
        $this->assertSame(
            ['mercure->publishParticipantChanged() failed'],
            array_column($this->logger->records, 'message'),
        );
        $this->assertSame(['exception' => 'hub unreachable'], $this->logger->records[0]['context']);
    }

    #[DataProvider('participantStatusesComingBack')]
    public function testAParticipantIsRestoredToAStatusThatExists(mixed $previousStatus, string $expected): void
    {
        // webcal_entry_user.cal_status is a CHAR(1) and ParticipantStatus
        // names the six it can hold. Anything else was written anyway.
        ($this->controller())(7, self::request(['previous_status' => $previousStatus]), self::user('bob'));

        $this->assertSame([['login' => 'bob', 'status' => $expected]], $this->participantWrites);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function participantStatusesComingBack(): iterable
    {
        yield 'waiting' => ['W', 'W'];
        yield 'in progress' => ['P', 'P'];
        yield 'completed' => ['C', 'C'];
        // The decline being undone: coming back as declined is not a restore.
        yield 'declined' => ['R', 'A'];
        yield 'nothing sent' => [null, 'A'];
        yield 'not a status' => ['Z', 'A'];
        yield 'longer than the column' => ['ACCEPTED', 'A'];
        yield 'a number' => [1, 'A'];
        yield 'an array' => [['A'], 'A'];
    }

    #[DataProvider('bodiesThatAreNotObjects')]
    public function testABodyThatIsNotAnObjectIsTreatedAsEmpty(string $body): void
    {
        $response = ($this->controller())(7, self::request($body), self::user('alice'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($this->storedStatus());
    }

    /** @return iterable<string, array{string}> */
    public static function bodiesThatAreNotObjects(): iterable
    {
        yield 'a bare string' => ['"just a string"'];
        yield 'not json at all' => ['<html>'];
        yield 'empty' => [''];
    }
}
