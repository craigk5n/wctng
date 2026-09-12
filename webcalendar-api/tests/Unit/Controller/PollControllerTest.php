<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Api\PollController;
use App\Poll\PollRepository;
use App\Security\WebCalendarUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use WebCalendar\Core\Application\Service\EventService;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\Repository\UserRepositoryInterface;

/**
 * Scheduling polls.
 *
 * Nothing in the unit suite executed a line of this controller, and the one
 * integration test walks the happy path end to end. What that leaves unsaid is
 * everything the routes do with input they did not produce: a poll is a thing
 * other people vote on, the votes decide which slot becomes a real event, and
 * three of the five routes take their ids and their values straight from the
 * request body.
 */
final class PollControllerTest extends TestCase
{
    private \PDO $pdo;
    private PollRepository $repo;
    /** @var list<Event> */
    private array $saved = [];

    #[\Override]
    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->repo = new PollRepository($this->pdo);
        $this->saved = [];
    }

    private function controller(): PollController
    {
        $events = $this->createMock(EventRepositoryInterface::class);
        $events->method('save')->willReturnCallback(function (Event $event): void {
            $this->saved[] = $event;
        });

        return new PollController(
            $this->repo,
            new EventService($events, $this->createMock(UserRepositoryInterface::class)),
        );
    }

    private static function user(string $login = 'alice'): WebCalendarUser
    {
        return new WebCalendarUser(new User($login, 'A', 'B', "{$login}@example.com", false, true), null);
    }

    private static function request(mixed $body): Request
    {
        $content = \is_string($body) ? $body : json_encode($body, \JSON_THROW_ON_ERROR);

        return Request::create('/api/v2/polls', 'POST', [], [], [], [], $content);
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

    /** @return list<array{start: string, end: string}> */
    private static function twoSlots(): array
    {
        return [
            ['start' => '2026-10-01 09:00:00', 'end' => '2026-10-01 10:00:00'],
            ['start' => '2026-10-02 14:00:00', 'end' => '2026-10-02 15:30:00'],
        ];
    }

    private function poll(string $creator = 'alice'): int
    {
        return $this->repo->createPoll($creator, 'Sprint planning', 'When?', self::twoSlots());
    }

    // ------------------------------------------------------- authentication

    #[DataProvider('anonymousCalls')]
    public function testEveryRouteRefusesAnAnonymousCaller(\Closure $call): void
    {
        $this->assertSame(401, $call($this->controller())->getStatusCode());
    }

    /** @return iterable<string, array{\Closure}> */
    public static function anonymousCalls(): iterable
    {
        yield 'create' => [static fn(PollController $c): JsonResponse => $c->create(self::request([]), null)];
        yield 'list' => [static fn(PollController $c): JsonResponse => $c->list(null)];
        yield 'get' => [static fn(PollController $c): JsonResponse => $c->get(1, null)];
        yield 'vote' => [static fn(PollController $c): JsonResponse => $c->vote(1, self::request([]), null)];
        yield 'finalize' => [static fn(PollController $c): JsonResponse => $c->finalize(1, null)];
    }

    // -------------------------------------------------------------- create

    public function testCreateStoresThePollAgainstItsCreator(): void
    {
        $response = $this->controller()->create(
            self::request(['title' => 'Sprint planning', 'description' => 'When?', 'options' => self::twoSlots()]),
            self::user('alice'),
        );

        $this->assertSame(201, $response->getStatusCode());
        $poll = self::payload($response)['data'];
        $this->assertSame('alice', $poll['creator']);
        $this->assertSame('Sprint planning', $poll['title']);
        $this->assertSame('When?', $poll['description']);
        $this->assertSame('open', $poll['status']);
        $this->assertCount(2, $poll['options']);
    }

    #[DataProvider('bodiesWithNoUsableDescription')]
    public function testCreateDefaultsTheDescriptionToNothing(array $body): void
    {
        // Optional, so a missing one and one of the wrong type both fall back
        // rather than refusing an otherwise good poll.
        $response = $this->controller()->create(self::request($body), self::user());

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('', self::payload($response)['data']['description']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function bodiesWithNoUsableDescription(): iterable
    {
        yield 'absent' => [['title' => 'Sprint planning', 'options' => self::twoSlots()]];
        yield 'an array' => [['title' => 'Sprint planning', 'description' => ['x'], 'options' => self::twoSlots()]];
        yield 'a number' => [['title' => 'Sprint planning', 'description' => 7, 'options' => self::twoSlots()]];
    }

    #[DataProvider('rejectedCreateBodies')]
    public function testCreateRefusesABodyItCannotBuildAPollFrom(mixed $body): void
    {
        $response = $this->controller()->create(self::request($body), self::user('alice'));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $this->repo->listByCreator('alice'), 'nothing may be stored for a rejected body');
    }

    /** @return iterable<string, array{mixed}> */
    public static function rejectedCreateBodies(): iterable
    {
        yield 'no title' => [['options' => self::twoSlots()]];
        yield 'empty title' => [['title' => '', 'options' => self::twoSlots()]];
        yield 'title is a number' => [['title' => 42, 'options' => self::twoSlots()]];
        yield 'title is an array' => [['title' => ['a'], 'options' => self::twoSlots()]];
        yield 'no options' => [['title' => 'T']];
        yield 'one option' => [['title' => 'T', 'options' => [self::twoSlots()[0]]]];
        yield 'options is not a list' => [['title' => 'T', 'options' => 'tomorrow']];
        yield 'options is a number' => [['title' => 'T', 'options' => 2]];
        yield 'an option is not an object' => [['title' => 'T', 'options' => ['a', 'b']]];
        yield 'an option has no end' => [['title' => 'T', 'options' => [
            ['start' => '2026-10-01 09:00:00'],
            ['start' => '2026-10-02 09:00:00', 'end' => '2026-10-02 10:00:00'],
        ]]];
        yield 'an option has no start' => [['title' => 'T', 'options' => [
            ['end' => '2026-10-01 10:00:00'],
            ['start' => '2026-10-02 09:00:00', 'end' => '2026-10-02 10:00:00'],
        ]]];
        yield 'not an object' => ['"just a string"'];
        yield 'not json at all' => ['<html>'];
        yield 'empty body' => [''];
    }

    /**
     * The column is a DATETIME. MySQL refuses anything it cannot read as one,
     * and it refuses it *after* the poll row has already gone in -- leaving a
     * poll with fewer options than were asked for, or none.
     */
    #[DataProvider('unusableSlots')]
    public function testCreateRefusesASlotThatIsNotATime(array $slot): void
    {
        $response = $this->controller()->create(
            self::request(['title' => 'T', 'options' => [$slot, self::twoSlots()[1]]]),
            self::user('alice'),
        );

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $this->repo->listByCreator('alice'));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function unusableSlots(): iterable
    {
        yield 'words' => [['start' => 'not a date', 'end' => 'also not']];
        yield 'empty strings' => [['start' => '', 'end' => '']];
        // Either one alone: an empty string is "now" to DateTimeImmutable, so
        // a slot with one would be stored as whenever the poll was created.
        yield 'empty start' => [['start' => '', 'end' => '2026-10-01 10:00:00']];
        yield 'empty end' => [['start' => '2026-10-01 09:00:00', 'end' => '']];
        yield 'start is a number' => [['start' => 20261001, 'end' => '2026-10-01 10:00:00']];
        yield 'end is null' => [['start' => '2026-10-01 09:00:00', 'end' => null]];
        yield 'ends before it starts' => [['start' => '2026-10-01 10:00:00', 'end' => '2026-10-01 09:00:00']];
        yield 'ends when it starts' => [['start' => '2026-10-01 09:00:00', 'end' => '2026-10-01 09:00:00']];
    }

    public function testCreateStoresSlotsInTheShapeTheColumnWants(): void
    {
        // Whatever the client wrote them as: the column is a DATETIME, and
        // MySQL takes 'Y-m-d H:i:s' rather than anything ISO 8601 offers.
        $response = $this->controller()->create(
            self::request(['title' => 'T', 'options' => [
                ['start' => '2026-10-01T09:00:00+00:00', 'end' => '2026-10-01T10:00:00+00:00'],
                ['start' => '2026-10-02 14:00:00', 'end' => '2026-10-02 15:30:00'],
            ]]),
            self::user(),
        );

        $this->assertSame(201, $response->getStatusCode());
        $options = self::payload($response)['data']['options'];
        $this->assertSame('2026-10-01 09:00:00', $options[0]['start']);
        $this->assertSame('2026-10-01 10:00:00', $options[0]['end']);
    }

    // ---------------------------------------------------------------- list

    public function testListShowsOnlyThePollsTheCallerMade(): void
    {
        $this->poll('alice');
        $this->poll('bob');

        $polls = self::payload($this->controller()->list(self::user('alice')))['data'];

        $this->assertCount(1, $polls);
        $this->assertSame('Sprint planning', $polls[0]['title']);
    }

    // ----------------------------------------------------------------- get

    public function testGetReportsAnUnknownPollAsMissing(): void
    {
        $this->assertSame(404, $this->controller()->get(4242, self::user())->getStatusCode());
    }

    public function testGetReturnsThePollWithItsOptions(): void
    {
        $id = $this->poll('alice');

        $poll = self::payload($this->controller()->get($id, self::user('alice')))['data'];

        $this->assertSame($id, $poll['id']);
        $this->assertCount(2, $poll['options']);
    }

    // ---------------------------------------------------------------- vote

    public function testVoteReportsAnUnknownPollAsMissing(): void
    {
        $this->assertSame(404, $this->controller()->vote(4242, self::request([]), self::user())->getStatusCode());
    }

    public function testVoteIsRefusedOnceThePollIsClosed(): void
    {
        $id = $this->poll('alice');
        $optionId = $this->repo->getPoll($id)['options'][0]['id'];
        $this->repo->closePoll($id);

        $response = $this->controller()->vote($id, self::request(['votes' => [$optionId => 'yes']]), self::user('bob'));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(0, $this->voteCount());
    }

    public function testVoteNeedsAtLeastOneVote(): void
    {
        $id = $this->poll('alice');

        $this->assertSame(400, $this->controller()->vote($id, self::request(['votes' => []]), self::user('bob'))->getStatusCode());
        $this->assertSame(400, $this->controller()->vote($id, self::request([]), self::user('bob'))->getStatusCode());
    }

    public function testVoteRecordsWhatWasSaidAboutEachSlot(): void
    {
        $id = $this->poll('alice');
        $options = $this->repo->getPoll($id)['options'];

        $response = $this->controller()->vote(
            $id,
            self::request(['votes' => [$options[0]['id'] => 'yes', $options[1]['id'] => 'no']]),
            self::user('bob'),
        );

        $this->assertSame(200, $response->getStatusCode());
        $returned = self::payload($response)['data']['options'];
        $this->assertSame(1, $returned[0]['yes_count']);
        $this->assertSame(0, $returned[1]['yes_count']);
        $this->assertSame([['voter' => 'bob', 'vote' => 'yes']], $returned[0]['votes']);
    }

    /**
     * castVotes() writes the option ids it is handed, and clears only the ones
     * belonging to the poll being voted on. A vote aimed at another poll's
     * option therefore lands on that poll and cannot be cleared by anyone
     * voting there -- a permanent entry in a ballot that decides which slot
     * becomes a real event.
     */
    public function testVoteCannotNameAnotherPollsOption(): void
    {
        $mine = $this->poll('mallory');
        $theirs = $this->poll('alice');
        $victimOption = $this->repo->getPoll($theirs)['options'][0]['id'];

        $response = $this->controller()->vote(
            $mine,
            self::request(['votes' => [$victimOption => 'yes']]),
            self::user('mallory'),
        );

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(0, $this->repo->getPoll($theirs)['options'][0]['yes_count']);
        $this->assertSame(0, $this->voteCount());
    }

    public function testVoteCannotNameAnOptionThatDoesNotExist(): void
    {
        $id = $this->poll('alice');

        $response = $this->controller()->vote($id, self::request(['votes' => [99999 => 'yes']]), self::user('bob'));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(0, $this->voteCount());
    }

    #[DataProvider('unusableVotes')]
    public function testVoteRefusesAnAnswerThatIsNotOneOfTheThree(mixed $vote): void
    {
        // 'YES' is stored happily and then counts for nothing: yes_count
        // matches on the exact string, so an answer outside the three reads as
        // a vote that was cast and a vote that does not exist at the same time.
        $id = $this->poll('alice');
        $optionId = $this->repo->getPoll($id)['options'][0]['id'];

        $response = $this->controller()->vote($id, self::request(['votes' => [$optionId => $vote]]), self::user('bob'));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(0, $this->voteCount());
        $this->assertSame(
            'Vote must be one of: yes, maybe, no',
            self::payload($response)['error']['message'],
            'the answer it will accept is the only useful thing a 400 here can say',
        );
    }

    /** @return iterable<string, array{mixed}> */
    public static function unusableVotes(): iterable
    {
        yield 'the wrong case' => ['YES'];
        yield 'something else entirely' => ['perhaps'];
        yield 'empty' => [''];
        yield 'a number' => [1];
        yield 'an array' => [['yes']];
    }

    public function testVotesAreNotADuplicateOfTheLastOnes(): void
    {
        $id = $this->poll('alice');
        $options = $this->repo->getPoll($id)['options'];

        $this->controller()->vote($id, self::request(['votes' => [$options[0]['id'] => 'yes']]), self::user('bob'));
        $this->controller()->vote($id, self::request(['votes' => [$options[1]['id'] => 'yes']]), self::user('bob'));

        $after = $this->repo->getPoll($id)['options'];
        $this->assertSame(0, $after[0]['yes_count'], 'the earlier answer should have been replaced');
        $this->assertSame(1, $after[1]['yes_count']);
    }

    // ------------------------------------------------------------ finalize

    public function testFinalizeReportsAnUnknownPollAsMissing(): void
    {
        $this->assertSame(404, $this->controller()->finalize(4242, self::user())->getStatusCode());
    }

    public function testOnlyTheCreatorMayFinalize(): void
    {
        $id = $this->poll('alice');

        $response = $this->controller()->finalize($id, self::user('bob'));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame([], $this->saved);
        $this->assertSame('open', $this->repo->getPoll($id)['status']);
    }

    public function testAClosedPollCannotBeFinalizedTwice(): void
    {
        $id = $this->poll('alice');
        $this->repo->closePoll($id);

        $this->assertSame(400, $this->controller()->finalize($id, self::user('alice'))->getStatusCode());
        $this->assertSame([], $this->saved);
    }

    public function testFinalizeTurnsTheMostPopularSlotIntoAnEvent(): void
    {
        $id = $this->poll('alice');
        $options = $this->repo->getPoll($id)['options'];
        $this->repo->castVotes($id, 'bob', [$options[1]['id'] => 'yes']);
        $this->repo->castVotes($id, 'carol', [$options[1]['id'] => 'yes']);
        $this->repo->castVotes($id, 'dave', [$options[0]['id'] => 'yes']);

        $response = $this->controller()->finalize($id, self::user('alice'));

        $this->assertSame(200, $response->getStatusCode());
        $data = self::payload($response)['data'];
        $this->assertSame($id, $data['poll_id']);
        $this->assertSame($options[1]['id'], $data['winning_option']['id']);
        $this->assertTrue($data['event_created']);

        $this->assertCount(1, $this->saved);
        $event = $this->saved[0];
        $this->assertSame('Sprint planning', $event->name());
        $this->assertSame('When?', $event->description());
        $this->assertSame('alice', $event->createdBy());
        $this->assertSame(sprintf('poll-%d@webcalendar', $id), $event->uid());
        $this->assertSame('2026-10-02 14:00:00', $event->start()->format('Y-m-d H:i:s'));
        $this->assertSame(90, $event->duration());
        // Id 0 is what marks it as new; with anything else the repository
        // takes the save for an update of whatever event already holds that id.
        $this->assertSame(0, $event->id()->value());

        $this->assertSame('closed', $this->repo->getPoll($id)['status']);
    }

    public function testFinalizeBreaksATieOnTheEarlierSlot(): void
    {
        // Options come back ordered by when they start, so the first one to
        // reach the top count keeps it -- the earlier slot wins.
        $id = $this->poll('alice');
        $options = $this->repo->getPoll($id)['options'];
        $this->repo->castVotes($id, 'bob', [$options[0]['id'] => 'yes']);
        $this->repo->castVotes($id, 'carol', [$options[1]['id'] => 'yes']);

        $response = $this->controller()->finalize($id, self::user('alice'));

        $this->assertSame($options[0]['id'], self::payload($response)['data']['winning_option']['id']);
        $this->assertSame('2026-10-01 09:00:00', $this->saved[0]->start()->format('Y-m-d H:i:s'));
    }

    public function testASlotThatIsNotAWholeNumberOfMinutesStillMakesAnEvent(): void
    {
        // PHP hands back an int only when the division comes out even, so
        // without the cast a ninety-second slot reaches Event's int duration
        // as 1.5 and takes the request down with a TypeError.
        $id = $this->repo->createPoll('alice', 'Stand-up', '', [
            ['start' => '2026-10-01 09:00:00', 'end' => '2026-10-01 09:01:30'],
            ['start' => '2026-10-02 09:00:00', 'end' => '2026-10-02 09:30:00'],
        ]);

        $response = $this->controller()->finalize($id, self::user('alice'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $this->saved[0]->duration());
    }

    public function testAPollNobodyVotedOnStillFinalizes(): void
    {
        $id = $this->poll('alice');

        $response = $this->controller()->finalize($id, self::user('alice'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $this->saved);
        $this->assertSame('closed', $this->repo->getPoll($id)['status']);
    }

    private function voteCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM scheduling_poll_votes')?->fetchColumn();
    }
}
