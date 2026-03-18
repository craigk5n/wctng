<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Poll\PollRepository;

final class PollIntegrationTest extends IntegrationTestCase
{
    public function testPollFullWorkflow(): void
    {
        $repo = new PollRepository($this->pdo);

        // Create poll
        $pollId = $repo->createPoll('admin', 'Team Sync', 'When should we meet?', [
            ['start' => '2026-06-01 10:00:00', 'end' => '2026-06-01 11:00:00'],
            ['start' => '2026-06-02 14:00:00', 'end' => '2026-06-02 15:00:00'],
            ['start' => '2026-06-03 09:00:00', 'end' => '2026-06-03 10:00:00'],
        ]);
        $this->assertGreaterThan(0, $pollId);

        // Get poll
        $poll = $repo->getPoll($pollId);
        $this->assertNotNull($poll);
        $this->assertSame('Team Sync', $poll['title']);
        $this->assertSame('open', $poll['status']);
        $this->assertCount(3, $poll['options']);

        // Cast votes
        $opt1 = $poll['options'][0]['id'];
        $opt2 = $poll['options'][1]['id'];
        $opt3 = $poll['options'][2]['id'];

        $repo->castVotes($pollId, 'admin', [$opt1 => 'yes', $opt2 => 'no', $opt3 => 'yes']);
        $repo->castVotes($pollId, 'alice', [$opt1 => 'yes', $opt2 => 'yes', $opt3 => 'no']);

        // Check vote counts
        $updated = $repo->getPoll($pollId);
        $this->assertSame(2, $updated['options'][0]['yes_count']); // opt1: admin+alice = 2
        $this->assertSame(1, $updated['options'][1]['yes_count']); // opt2: alice only = 1
        $this->assertSame(1, $updated['options'][2]['yes_count']); // opt3: admin only = 1

        // Finalize
        $repo->closePoll($pollId);
        $finalized = $repo->getPoll($pollId);
        $this->assertSame('closed', $finalized['status']);
    }
}
