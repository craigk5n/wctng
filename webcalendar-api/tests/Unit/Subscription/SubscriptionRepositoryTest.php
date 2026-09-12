<?php

declare(strict_types=1);

namespace App\Tests\Unit\Subscription;

use App\Subscription\SubscriptionRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

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

    // ------------------------------------------------ more than one at a time

    public function testFindByUserReturnsEveryOneOfThem(): void
    {
        // Both list methods were only ever asked for one subscription, so
        // truncating either result to its first entry was invisible -- and a
        // user with three calendars would silently see one.
        $this->repo->create('alice', 'https://a.example/1.ics', 'Alpha', '#111');
        $this->repo->create('alice', 'https://a.example/2.ics', 'Beta', '#222');
        $this->repo->create('alice', 'https://a.example/3.ics', 'Gamma', '#333');

        $found = $this->repo->findByUser('alice');

        $this->assertCount(3, $found);
        $this->assertSame(
            ['Alpha', 'Beta', 'Gamma'],
            array_map(static fn(object $s): string => $s->name(), $found),
        );
    }

    public function testEveryNeverFetchedSubscriptionIsDueForRefresh(): void
    {
        // The refresh worker walks this list; if it only ever gets the first
        // entry, every calendar but one stops updating and nothing reports it.
        $this->repo->create('alice', 'https://a.example/1.ics', 'Alpha', '#111');
        $this->repo->create('bob', 'https://b.example/2.ics', 'Beta', '#222');
        $this->repo->create('carol', 'https://c.example/3.ics', 'Gamma', '#333');

        $this->assertCount(3, $this->repo->findDueForRefresh());
    }

    // ------------------------------------------------------- the default gap

    public function testASubscriptionCreatedWithoutAnIntervalRefreshesHourly(): void
    {
        // Every existing case passes an interval or ignores it, so the default
        // in the signature was never read back. It is the cadence every
        // subscription added through the UI inherits.
        $sub = $this->repo->create('alice', 'https://a.example/cal.ics', 'Hourly', '#111');

        $this->assertSame(3600, $sub->refreshInterval());
        $this->assertSame(3600, $this->repo->findById($sub->id())?->refreshInterval());
    }

    public function testTheRowIsReadAsIntsOnAStringifyingDriver(): void
    {
        // MySQL's PDO hands back strings, which is what the casts on id and
        // refresh_interval are for; on SQLite they look like no-ops. The
        // interval feeds arithmetic in the due-for-refresh query, and the id
        // is what delete() and updateFetchStatus() are called with.
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, true);
        $repo = new SubscriptionRepository($pdo);

        $created = $repo->create('alice', 'https://a.example/cal.ics', 'Strings', '#111', 7200);
        $found = $repo->findById($created->id());

        $this->assertNotNull($found);
        $this->assertSame($created->id(), $found->id());
        $this->assertSame(7200, $found->refreshInterval());
    }

    // ------------------------------------------------------- the table itself

    /** @return iterable<string, array{\Closure(SubscriptionRepository): mixed}> */
    public static function readsOnAnEmptyDatabase(): iterable
    {
        // All three read paths create the table before querying it. Without
        // that, the first request after a deploy fails with "no such table"
        // instead of reporting that nothing is subscribed.
        yield 'findByUser' => [static fn(SubscriptionRepository $r): mixed => $r->findByUser('alice')];
        yield 'findById' => [static fn(SubscriptionRepository $r): mixed => $r->findById(1)];
        yield 'findDueForRefresh' => [static fn(SubscriptionRepository $r): mixed => $r->findDueForRefresh()];
    }

    /** @param \Closure(SubscriptionRepository): mixed $read */
    #[DataProvider('readsOnAnEmptyDatabase')]
    public function testAReadOnAFreshDatabaseCreatesTheTableFirst(\Closure $read): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $result = $read(new SubscriptionRepository($pdo));

        $this->assertTrue($result === null || $result === []);
    }

    // ------------------------------------------------------- when it is due

    /**
     * `datetime('now')` is SQLite's, and the deployments that matter run
     * MySQL, which rejects it -- so this stamp was never written anywhere but
     * a test. It is now taken from the injected clock, in UTC, which is what
     * the old expression produced.
     */
    public function testTheFetchStampIsWrittenInUtc(): void
    {
        $clock = new MockClock(new \DateTimeImmutable('2026-04-01 12:00:00', new \DateTimeZone('America/New_York')));
        $repo = new SubscriptionRepository($this->pdo, $clock);
        $sub = $repo->create('alice', 'https://a.example/cal.ics', 'Hourly', '#111');

        $repo->updateFetchStatus($sub->id(), '"v1"');

        $this->assertSame('2026-04-01 16:00:00', $repo->findById($sub->id())?->lastFetched());
    }

    public function testASubscriptionFetchedWithinItsIntervalIsNotDue(): void
    {
        $clock = new MockClock(new \DateTimeImmutable('2026-04-01 12:00:00', new \DateTimeZone('UTC')));
        $repo = new SubscriptionRepository($this->pdo, $clock);
        $sub = $repo->create('alice', 'https://a.example/cal.ics', 'Hourly', '#111', 3600);
        $repo->updateFetchStatus($sub->id(), '"v1"');

        $clock->modify('+59 minutes');

        $this->assertSame([], $repo->findDueForRefresh());
    }

    public function testASubscriptionFetchedLongerAgoThanItsIntervalIsDue(): void
    {
        $clock = new MockClock(new \DateTimeImmutable('2026-04-01 12:00:00', new \DateTimeZone('UTC')));
        $repo = new SubscriptionRepository($this->pdo, $clock);
        $sub = $repo->create('alice', 'https://a.example/cal.ics', 'Hourly', '#111', 3600);
        $repo->updateFetchStatus($sub->id(), '"v1"');

        $clock->modify('+61 minutes');

        $this->assertCount(1, $repo->findDueForRefresh());
    }

    public function testEachSubscriptionIsMeasuredAgainstItsOwnInterval(): void
    {
        // One cutoff for the whole table would make the shorter interval wait
        // for the longer one, or refresh the longer one far too often.
        $clock = new MockClock(new \DateTimeImmutable('2026-04-01 12:00:00', new \DateTimeZone('UTC')));
        $repo = new SubscriptionRepository($this->pdo, $clock);
        $quick = $repo->create('alice', 'https://a.example/quick.ics', 'Quick', '#111', 900);
        $slow = $repo->create('alice', 'https://a.example/slow.ics', 'Slow', '#222', 86400);
        $repo->updateFetchStatus($quick->id(), null);
        $repo->updateFetchStatus($slow->id(), null);

        $clock->modify('+30 minutes');

        $due = $repo->findDueForRefresh();
        $this->assertSame(['Quick'], array_map(static fn(object $s): string => $s->name(), $due));
    }

    public function testASubscriptionIsDueExactlyOneIntervalAfterItWasFetched(): void
    {
        // The boundary decides whether an hourly calendar refreshes on the
        // hour or an hour and a tick later, every time, forever.
        $clock = new MockClock(new \DateTimeImmutable('2026-04-01 12:00:00', new \DateTimeZone('UTC')));
        $repo = new SubscriptionRepository($this->pdo, $clock);
        $sub = $repo->create('alice', 'https://a.example/cal.ics', 'Hourly', '#111', 3600);
        $repo->updateFetchStatus($sub->id(), '"v1"');

        $clock->modify('+3600 seconds');

        $this->assertCount(1, $repo->findDueForRefresh());
    }

    public function testAnUnreadableFetchStampLeavesTheSubscriptionDue(): void
    {
        // Whatever wrote it, it is not evidence the feed is fresh.
        $clock = new MockClock(new \DateTimeImmutable('2026-04-01 12:00:00', new \DateTimeZone('UTC')));
        $repo = new SubscriptionRepository($this->pdo, $clock);
        $sub = $repo->create('alice', 'https://a.example/cal.ics', 'Hourly', '#111', 3600);
        $this->pdo->prepare('UPDATE calendar_subscriptions SET last_fetched = :stamp WHERE id = :id')
            ->execute(['stamp' => 'not a date', 'id' => $sub->id()]);

        $this->assertCount(1, $repo->findDueForRefresh());
    }

    public function testADeleteOnAFreshDatabaseReportsTheRowMissing(): void
    {
        // The other entry points create the table; this one did not, so the
        // first delete after a deploy raised "no such table" instead of 404.
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $this->assertFalse((new SubscriptionRepository($pdo))->delete(1, 'alice'));
    }
}
