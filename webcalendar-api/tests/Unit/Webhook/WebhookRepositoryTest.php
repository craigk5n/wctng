<?php

declare(strict_types=1);

namespace App\Tests\Unit\Webhook;

use App\Webhook\WebhookRepository;
use App\Webhook\WebhookSubscription;
use PHPUnit\Framework\Attributes\DataProvider;
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

    // ------------------------------------------------ every column, read back

    private const SECRET = 'whsec-not-a-real-signing-key';

    private function fullyPopulated(int $id = 0, bool $enabled = true): WebhookSubscription
    {
        return new WebhookSubscription(
            $id,
            'https://hooks.example.com/incoming/abc123',
            'event.created,event.updated',
            self::SECRET,
            $enabled,
        );
    }

    public function testEveryColumnSurvivesTheRoundTrip(): void
    {
        // The secret signs every delivery and the events string decides which
        // deliveries happen at all; neither was read back anywhere.
        $id = $this->repo->save($this->fullyPopulated());

        $found = $this->repo->findById($id);

        $this->assertNotNull($found);
        $this->assertSame($id, $found->id());
        $this->assertSame('https://hooks.example.com/incoming/abc123', $found->url());
        $this->assertSame('event.created,event.updated', $found->events());
        $this->assertSame(self::SECRET, $found->secret());
        $this->assertTrue($found->isEnabled());
    }

    public function testTheRowIsReadAsIntsAndBoolsOnAStringifyingDriver(): void
    {
        // MySQL's PDO returns every column as a string; on SQLite the casts on
        // id and enabled look like no-ops. The id is what delete() and the
        // admin API are called with, and enabled decides whether the
        // dispatcher even looks at the row.
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, true);
        $repo = new WebhookRepository($pdo);

        $id = $repo->save($this->fullyPopulated());
        $found = $repo->findById($id);

        $this->assertNotNull($found);
        $this->assertSame($id, $found->id());
        $this->assertTrue($found->isEnabled());
    }

    // ------------------------------------------------- insert versus update

    public function testUpdatingAWebhookDoesNotAlsoInsertACopyOfIt(): void
    {
        // save() branches on whether the subscription carries an id, and the
        // update branch has to return rather than fall through to the INSERT
        // below it. Without that return every edit leaves a duplicate -- and a
        // duplicated webhook means the endpoint receives every event twice.
        $id = $this->repo->save($this->fullyPopulated());

        $returned = $this->repo->save(new WebhookSubscription(
            $id,
            'https://hooks.example.com/incoming/renamed',
            'event.created',
            self::SECRET,
            true,
        ));

        $this->assertSame($id, $returned, 'an update reports the id it edited');
        $this->assertCount(1, $this->repo->findAll());
        $this->assertSame('https://hooks.example.com/incoming/renamed', $this->repo->findById($id)?->url());
    }

    /** @return iterable<string, array{bool}> */
    public static function enabledStates(): iterable
    {
        yield 'enabled' => [true];
        yield 'disabled' => [false];
    }

    #[DataProvider('enabledStates')]
    public function testTheEnabledFlagSurvivesAnInsert(bool $enabled): void
    {
        $id = $this->repo->save($this->fullyPopulated(enabled: $enabled));

        $this->assertSame($enabled, $this->repo->findById($id)?->isEnabled());
    }

    #[DataProvider('enabledStates')]
    public function testTheEnabledFlagSurvivesAnUpdate(bool $enabled): void
    {
        // Only one direction was covered, so the flag could have been written
        // as a constant on update: either switching every edited webhook off,
        // or quietly re-enabling one an admin had just disabled.
        $id = $this->repo->save($this->fullyPopulated(enabled: !$enabled));

        $this->repo->save($this->fullyPopulated($id, $enabled));

        $this->assertSame($enabled, $this->repo->findById($id)?->isEnabled());
    }

    public function testOnlyEnabledWebhooksAreOfferedToTheDispatcher(): void
    {
        $this->repo->save($this->fullyPopulated(enabled: true));
        $this->repo->save(new WebhookSubscription(0, 'https://off.example.com/hook', '*', 'x', false));

        $enabled = $this->repo->findEnabled();

        $this->assertCount(1, $enabled);
        $this->assertSame('https://hooks.example.com/incoming/abc123', array_values($enabled)[0]->url());
    }

    // ------------------------------------------------------- the table itself

    /** @return iterable<string, array{\Closure(WebhookRepository): mixed}> */
    public static function readsOnAnEmptyDatabase(): iterable
    {
        // Both read paths create the table before querying it; without that
        // the first request after a deploy fails with "no such table" rather
        // than reporting that nothing is subscribed.
        yield 'findAll' => [static fn(WebhookRepository $r): mixed => $r->findAll()];
        yield 'findById' => [static fn(WebhookRepository $r): mixed => $r->findById(1)];
        yield 'findEnabled' => [static fn(WebhookRepository $r): mixed => $r->findEnabled()];
    }

    /** @param \Closure(WebhookRepository): mixed $read */
    #[DataProvider('readsOnAnEmptyDatabase')]
    public function testAReadOnAFreshDatabaseCreatesTheTableFirst(\Closure $read): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $result = $read(new WebhookRepository($pdo));

        $this->assertTrue($result === null || $result === []);
    }
}
