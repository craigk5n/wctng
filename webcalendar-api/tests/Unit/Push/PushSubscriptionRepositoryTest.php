<?php

declare(strict_types=1);

namespace App\Tests\Unit\Push;

use App\Push\PushSubscriptionRepository;
use PHPUnit\Framework\TestCase;

final class PushSubscriptionRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private PushSubscriptionRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->repo = new PushSubscriptionRepository($this->pdo);
    }

    public function testSubscribeAndFind(): void
    {
        $this->repo->subscribe('alice', 'https://push.example.com/sub1', 'p256dh_key', 'auth_key');

        $subs = $this->repo->getSubscriptionsForUser('alice');
        $this->assertCount(1, $subs);
        $this->assertSame('https://push.example.com/sub1', $subs[0]['endpoint']);
        $this->assertSame('p256dh_key', $subs[0]['p256dh']);
    }

    public function testSubscribeUpdatesExisting(): void
    {
        $this->repo->subscribe('alice', 'https://push.example.com/sub1', 'key1', 'auth1');
        $this->repo->subscribe('alice', 'https://push.example.com/sub1', 'key2', 'auth2');

        $subs = $this->repo->getSubscriptionsForUser('alice');
        $this->assertCount(1, $subs);
        $this->assertSame('key2', $subs[0]['p256dh']);
    }

    public function testUnsubscribe(): void
    {
        $this->repo->subscribe('alice', 'https://push.example.com/sub1', 'k', 'a');
        $this->repo->unsubscribe('https://push.example.com/sub1');

        $this->assertCount(0, $this->repo->getSubscriptionsForUser('alice'));
    }

    public function testMultipleUsers(): void
    {
        $this->repo->subscribe('alice', 'https://push.example.com/a', 'k', 'a');
        $this->repo->subscribe('bob', 'https://push.example.com/b', 'k', 'a');

        $this->assertCount(1, $this->repo->getSubscriptionsForUser('alice'));
        $this->assertCount(1, $this->repo->getSubscriptionsForUser('bob'));
    }
}
