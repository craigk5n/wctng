<?php

declare(strict_types=1);

namespace App\Tests\Unit\Subscription;

use App\Subscription\SubscriptionRepository;
use PHPUnit\Framework\TestCase;

final class SubscriptionRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private SubscriptionRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->repo = new SubscriptionRepository($this->pdo);
    }

    public function testCreateAndFindByUser(): void
    {
        $sub = $this->repo->create('alice', 'https://example.com/holidays.ics', 'US Holidays', '#ff0000');

        $this->assertGreaterThan(0, $sub->id());
        $this->assertSame('alice', $sub->userLogin());
        $this->assertSame('US Holidays', $sub->name());

        $subs = $this->repo->findByUser('alice');
        $this->assertCount(1, $subs);
        $this->assertSame('https://example.com/holidays.ics', $subs[0]->url());
    }

    public function testFindByUserReturnsOnlyOwnedSubscriptions(): void
    {
        $this->repo->create('alice', 'https://a.com/cal.ics', 'Alice Cal', '#aaa');
        $this->repo->create('bob', 'https://b.com/cal.ics', 'Bob Cal', '#bbb');

        $this->assertCount(1, $this->repo->findByUser('alice'));
        $this->assertCount(1, $this->repo->findByUser('bob'));
        $this->assertCount(0, $this->repo->findByUser('carol'));
    }

    public function testDelete(): void
    {
        $sub = $this->repo->create('alice', 'https://example.com/cal.ics', 'Test', '#000');
        $deleted = $this->repo->delete($sub->id(), 'alice');
        $this->assertTrue($deleted);
        $this->assertNull($this->repo->findById($sub->id()));
    }

    public function testDeleteWrongUser(): void
    {
        $sub = $this->repo->create('alice', 'https://example.com/cal.ics', 'Test', '#000');
        $deleted = $this->repo->delete($sub->id(), 'bob');
        $this->assertFalse($deleted);
        $this->assertNotNull($this->repo->findById($sub->id()));
    }

    public function testUpdateFetchStatus(): void
    {
        $sub = $this->repo->create('alice', 'https://example.com/cal.ics', 'Test', '#000');
        $this->assertNull($sub->lastFetched());

        $this->repo->updateFetchStatus($sub->id(), '"etag123"');

        $updated = $this->repo->findById($sub->id());
        $this->assertNotNull($updated);
        $this->assertNotNull($updated->lastFetched());
        $this->assertSame('"etag123"', $updated->etag());
    }

    public function testFindDueForRefresh(): void
    {
        // New subscription (never fetched) should be due
        $this->repo->create('alice', 'https://example.com/cal.ics', 'New', '#000');

        $due = $this->repo->findDueForRefresh();
        $this->assertCount(1, $due);
    }

    public function testToArray(): void
    {
        $sub = $this->repo->create('alice', 'https://example.com/cal.ics', 'Holidays', '#ff0000', 7200);
        $arr = $sub->toArray();

        $this->assertSame('alice', $arr['user_login']);
        $this->assertSame('https://example.com/cal.ics', $arr['url']);
        $this->assertSame('Holidays', $arr['name']);
        $this->assertSame('#ff0000', $arr['color']);
        $this->assertSame(7200, $arr['refresh_interval']);
    }
}
