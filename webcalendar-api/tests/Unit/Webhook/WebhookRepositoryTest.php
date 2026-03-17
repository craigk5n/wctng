<?php

declare(strict_types=1);

namespace App\Tests\Unit\Webhook;

use App\Webhook\WebhookRepository;
use App\Webhook\WebhookSubscription;
use PHPUnit\Framework\TestCase;

final class WebhookRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private WebhookRepository $repo;

    #[\Override]
    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->repo = new WebhookRepository($this->pdo);
    }

    public function testSaveAndFindById(): void
    {
        $id = $this->repo->save(new WebhookSubscription(0, 'https://example.com/hook', 'event.created,event.deleted', 'secret123', true));
        $this->assertGreaterThan(0, $id);

        $found = $this->repo->findById($id);
        $this->assertNotNull($found);
        $this->assertSame('https://example.com/hook', $found->url());
        $this->assertSame('event.created,event.deleted', $found->events());
        $this->assertSame('secret123', $found->secret());
        $this->assertTrue($found->isEnabled());
    }

    public function testFindAll(): void
    {
        $this->repo->save(new WebhookSubscription(0, 'https://a.com', '*', 's1', true));
        $this->repo->save(new WebhookSubscription(0, 'https://b.com', '*', 's2', false));

        $all = $this->repo->findAll();
        $this->assertCount(2, $all);
    }

    public function testFindEnabled(): void
    {
        $this->repo->save(new WebhookSubscription(0, 'https://a.com', '*', 's1', true));
        $this->repo->save(new WebhookSubscription(0, 'https://b.com', '*', 's2', false));

        $enabled = $this->repo->findEnabled();
        $this->assertCount(1, $enabled);
        $this->assertSame('https://a.com', $enabled[0]->url());
    }

    public function testUpdate(): void
    {
        $id = $this->repo->save(new WebhookSubscription(0, 'https://old.com', '*', 's', true));
        $this->repo->save(new WebhookSubscription($id, 'https://new.com', 'event.created', 'new-s', false));

        $found = $this->repo->findById($id);
        $this->assertNotNull($found);
        $this->assertSame('https://new.com', $found->url());
        $this->assertFalse($found->isEnabled());
    }

    public function testDelete(): void
    {
        $id = $this->repo->save(new WebhookSubscription(0, 'https://del.com', '*', 's', true));
        $this->repo->delete($id);
        $this->assertNull($this->repo->findById($id));
    }

    public function testSubscribesToEvent(): void
    {
        $webhook = new WebhookSubscription(1, 'https://x.com', 'event.created,event.deleted', 's', true);
        $this->assertTrue($webhook->subscribesTo('event.created'));
        $this->assertTrue($webhook->subscribesTo('event.deleted'));
        $this->assertFalse($webhook->subscribesTo('event.updated'));
    }

    public function testWildcardSubscribesAll(): void
    {
        $webhook = new WebhookSubscription(1, 'https://x.com', '*', 's', true);
        $this->assertTrue($webhook->subscribesTo('event.created'));
        $this->assertTrue($webhook->subscribesTo('anything'));
    }

    public function testToArrayExcludesSecret(): void
    {
        $webhook = new WebhookSubscription(1, 'https://x.com', '*', 'top-secret', true);
        $array = $webhook->toArray();
        $this->assertArrayNotHasKey('secret', $array);
    }

    public function testHmacSignature(): void
    {
        $payload = '{"type":"event.created"}';
        $secret = 'webhook-secret';
        $signature = hash_hmac('sha256', $payload, $secret);

        $this->assertSame(64, \strlen($signature));
        $this->assertSame($signature, hash_hmac('sha256', $payload, $secret)); // Deterministic
    }
}
