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
}
