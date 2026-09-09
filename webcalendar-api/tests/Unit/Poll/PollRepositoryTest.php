<?php

declare(strict_types=1);

namespace App\Tests\Unit\Poll;

use App\Poll\PollRepository;
use PHPUnit\Framework\TestCase;

final class PollRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private PollRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->repo = new PollRepository($this->pdo);
    }

    public function testCreateAndGetPoll(): void
    {
        $id = $this->repo->createPoll('admin', 'Team Meeting', 'Find a time', [
            ['start' => '2026-06-01 10:00:00', 'end' => '2026-06-01 11:00:00'],
            ['start' => '2026-06-02 14:00:00', 'end' => '2026-06-02 15:00:00'],
        ]);

        $this->assertGreaterThan(0, $id);

        $poll = $this->repo->getPoll($id);
        $this->assertNotNull($poll);
        $this->assertSame('Team Meeting', $poll['title']);
        $this->assertSame('admin', $poll['creator']);
        $this->assertSame('open', $poll['status']);
        $this->assertCount(2, $poll['options']);
    }

    public function testCastVotes(): void
    {
        $pollId = $this->repo->createPoll('admin', 'Poll', '', [
            ['start' => '2026-06-01 10:00:00', 'end' => '2026-06-01 11:00:00'],
            ['start' => '2026-06-02 14:00:00', 'end' => '2026-06-02 15:00:00'],
        ]);

        $poll = $this->repo->getPoll($pollId);
        $opt1 = $poll['options'][0]['id'];
        $opt2 = $poll['options'][1]['id'];

        $this->repo->castVotes($pollId, 'alice', [$opt1 => 'yes', $opt2 => 'no']);
        $this->repo->castVotes($pollId, 'bob', [$opt1 => 'yes', $opt2 => 'yes']);

        $updated = $this->repo->getPoll($pollId);
        $this->assertSame(2, $updated['options'][0]['yes_count']);
        $this->assertSame(1, $updated['options'][1]['yes_count']);
    }

    public function testRecastVotesReplacesOld(): void
    {
        $pollId = $this->repo->createPoll('admin', 'Poll', '', [
            ['start' => '2026-06-01 10:00:00', 'end' => '2026-06-01 11:00:00'],
        ]);

        $poll = $this->repo->getPoll($pollId);
        $optId = $poll['options'][0]['id'];

        $this->repo->castVotes($pollId, 'alice', [$optId => 'yes']);
        $this->repo->castVotes($pollId, 'alice', [$optId => 'no']); // change vote

        $updated = $this->repo->getPoll($pollId);
        $this->assertSame(0, $updated['options'][0]['yes_count']);
        $this->assertCount(1, $updated['options'][0]['votes']);
    }

    public function testClosePoll(): void
    {
        $pollId = $this->repo->createPoll('admin', 'Close Me', '', [
            ['start' => '2026-06-01 10:00:00', 'end' => '2026-06-01 11:00:00'],
        ]);

        $this->repo->closePoll($pollId);

        $poll = $this->repo->getPoll($pollId);
        $this->assertSame('closed', $poll['status']);
    }

    public function testListByCreator(): void
    {
        $this->repo->createPoll('admin', 'Poll A', '', [
            ['start' => '2026-06-01 10:00:00', 'end' => '2026-06-01 11:00:00'],
        ]);
        $this->repo->createPoll('admin', 'Poll B', '', [
            ['start' => '2026-06-02 10:00:00', 'end' => '2026-06-02 11:00:00'],
        ]);
        $this->repo->createPoll('alice', 'Alice Poll', '', [
            ['start' => '2026-06-03 10:00:00', 'end' => '2026-06-03 11:00:00'],
        ]);

        $adminPolls = $this->repo->listByCreator('admin');
        $this->assertCount(2, $adminPolls);

        $alicePolls = $this->repo->listByCreator('alice');
        $this->assertCount(1, $alicePolls);
    }

    public function testGetNonexistentPoll(): void
    {
        $this->assertNull($this->repo->getPoll(999));
    }

    // --- the whole row, not just the fields somebody happened to read ---

    public function testAPollCarriesEveryFieldItPromises(): void
    {
        // Each field is `is_string($row[k] ?? null) ? $row[k] : ''`, and
        // swapping the arms of any of them returns the empty fallback for a
        // perfectly good value. Only title, creator and status were read.
        $id = $this->repo->createPoll('admin', 'Team Meeting', 'Find a time that works', [
            ['start' => '2026-06-01 10:00:00', 'end' => '2026-06-01 11:00:00'],
        ]);

        $poll = $this->repo->getPoll($id);

        self::assertNotNull($poll);
        self::assertSame($id, $poll['id']);
        self::assertSame('admin', $poll['creator']);
        self::assertSame('Team Meeting', $poll['title']);
        self::assertSame('Find a time that works', $poll['description']);
        self::assertSame('open', $poll['status']);
        self::assertNotSame('', $poll['created_at'], 'the row is stamped by the schema default');
    }

    public function testAnOptionCarriesBothEndsOfItsSlot(): void
    {
        $id = $this->repo->createPoll('admin', 'Poll', '', [
            ['start' => '2026-06-01 10:00:00', 'end' => '2026-06-01 11:00:00'],
            ['start' => '2026-06-02 14:00:00', 'end' => '2026-06-02 15:30:00'],
        ]);

        $poll = $this->repo->getPoll($id);

        self::assertNotNull($poll);
        self::assertSame(
            [['2026-06-01 10:00:00', '2026-06-01 11:00:00'], ['2026-06-02 14:00:00', '2026-06-02 15:30:00']],
            array_map(static fn(array $o): array => [$o['start'], $o['end']], $poll['options']),
        );
    }

    public function testEachVoteRecordsWhoCastItAndWhatTheySaid(): void
    {
        $id = $this->repo->createPoll('admin', 'Poll', '', [
            ['start' => '2026-06-01 10:00:00', 'end' => '2026-06-01 11:00:00'],
        ]);
        $poll = $this->repo->getPoll($id);
        self::assertNotNull($poll);
        $option = $poll['options'][0]['id'];

        $this->repo->castVotes($id, 'alice', [$option => 'yes']);
        $this->repo->castVotes($id, 'bob', [$option => 'maybe']);

        $updated = $this->repo->getPoll($id);
        self::assertNotNull($updated);
        self::assertSame(
            [['voter' => 'alice', 'vote' => 'yes'], ['voter' => 'bob', 'vote' => 'maybe']],
            $updated['options'][0]['votes'],
        );
    }

    public function testOnlyYesVotesAreCounted(): void
    {
        $id = $this->repo->createPoll('admin', 'Poll', '', [
            ['start' => '2026-06-01 10:00:00', 'end' => '2026-06-01 11:00:00'],
        ]);
        $poll = $this->repo->getPoll($id);
        self::assertNotNull($poll);
        $option = $poll['options'][0]['id'];

        $this->repo->castVotes($id, 'alice', [$option => 'yes']);
        $this->repo->castVotes($id, 'bob', [$option => 'maybe']);
        $this->repo->castVotes($id, 'carol', [$option => 'no']);

        $updated = $this->repo->getPoll($id);
        self::assertNotNull($updated);
        self::assertCount(3, $updated['options'][0]['votes']);
        self::assertSame(1, $updated['options'][0]['yes_count'], 'maybe and no are not yes');
    }

    public function testTheListCarriesEveryFieldItPromises(): void
    {
        $id = $this->repo->createPoll('admin', 'Listed Poll', 'ignored by the list', [
            ['start' => '2026-06-01 10:00:00', 'end' => '2026-06-01 11:00:00'],
        ]);

        $listed = $this->repo->listByCreator('admin');

        self::assertCount(1, $listed);
        self::assertSame($id, $listed[0]['id']);
        self::assertSame('Listed Poll', $listed[0]['title']);
        self::assertSame('open', $listed[0]['status']);
        self::assertNotSame('', $listed[0]['created_at']);
        self::assertSame(['id', 'title', 'status', 'created_at'], array_keys($listed[0]));
    }

    public function testAClosedPollSaysSoInBothViews(): void
    {
        $id = $this->repo->createPoll('admin', 'Poll', '', [
            ['start' => '2026-06-01 10:00:00', 'end' => '2026-06-01 11:00:00'],
        ]);

        $this->repo->closePoll($id);

        $poll = $this->repo->getPoll($id);
        self::assertNotNull($poll);
        self::assertSame('closed', $poll['status']);
        self::assertSame('closed', $this->repo->listByCreator('admin')[0]['status']);
    }

    // --- the schema is created on demand ---

    public function testReadingFromAnEmptyDatabaseCreatesTheTablesFirst(): void
    {
        // listByCreator() is reachable before any poll exists -- it is what the
        // list page calls -- so it has to create the schema rather than fail on
        // a missing table.
        self::assertSame([], $this->repo->listByCreator('nobody'));
    }

    public function testVotingCreatesTheTablesFirstToo(): void
    {
        $fresh = new \PDO('sqlite::memory:');
        $fresh->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        (new PollRepository($fresh))->castVotes(1, 'alice', []);

        self::assertSame([], (new PollRepository($fresh))->listByCreator('alice'));
    }

    // --- driver independence ---

    public function testRowsAreTypedTheSameWhicheverWayTheDriverReturnsColumns(): void
    {
        // SQLite hands back a native int for id; MySQL stringifies every
        // column. The casts in the row mapping are what make the two agree,
        // and the same guard written without one has already shipped an empty
        // start_date twice in this codebase.
        $mysqlish = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_STRINGIFY_FETCHES => true]);
        $mysqlish->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $other = new PollRepository($mysqlish);

        $id = $other->createPoll('admin', 'Poll', 'desc', [
            ['start' => '2026-06-01 10:00:00', 'end' => '2026-06-01 11:00:00'],
        ]);
        $poll = $other->getPoll($id);

        self::assertNotNull($poll);
        self::assertIsInt($poll['id']);
        self::assertIsInt($poll['options'][0]['id']);
        self::assertIsInt($other->listByCreator('admin')[0]['id']);
    }
}
