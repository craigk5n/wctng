<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\UserTokenIndex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Covers PBP-S6 UserTokenIndex — the per-user `jti` book that
 * `logout-all` / force-logout / password-change iterates to hand every
 * live token to Lexik's blocklist.
 */
final class UserTokenIndexTest extends TestCase
{
    private \PDO $pdo;
    private MockClock $clock;
    private UserTokenIndex $index;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->clock = new MockClock('2026-04-15T12:00:00+00:00');
        $this->index = new UserTokenIndex($this->pdo, $this->clock);
    }

    public function testRecordAndListLiveTokens(): void
    {
        $exp = $this->clock->now()->getTimestamp() + 3600;
        $this->index->record('alice', 'jti-1', $exp);
        $this->index->record('alice', 'jti-2', $exp);
        $this->index->record('bob', 'jti-3', $exp);

        $alice = $this->index->liveTokensFor('alice');
        $this->assertCount(2, $alice);
        $this->assertSame(['jti-1', 'jti-2'], array_column($alice, 'jti'));

        $bob = $this->index->liveTokensFor('bob');
        $this->assertSame([['jti' => 'jti-3', 'exp' => $exp]], $bob);
    }

    public function testForgetRemovesSingleJti(): void
    {
        $exp = $this->clock->now()->getTimestamp() + 3600;
        $this->index->record('alice', 'jti-1', $exp);
        $this->index->record('alice', 'jti-2', $exp);

        $this->index->forget('jti-1');

        $this->assertSame(['jti-2'], array_column($this->index->liveTokensFor('alice'), 'jti'));
    }

    public function testForgetAllForWipesLogin(): void
    {
        $exp = $this->clock->now()->getTimestamp() + 3600;
        $this->index->record('alice', 'jti-1', $exp);
        $this->index->record('alice', 'jti-2', $exp);

        $this->index->forgetAllFor('alice');

        $this->assertSame([], $this->index->liveTokensFor('alice'));
    }

    public function testExpiredRowsAreFilteredOnList(): void
    {
        $now = $this->clock->now()->getTimestamp();
        $this->index->record('alice', 'live-jti', $now + 3600);
        $this->index->record('alice', 'dead-jti', $now - 10);

        $this->assertSame(['live-jti'], array_column($this->index->liveTokensFor('alice'), 'jti'));
    }

    public function testDuplicateRecordSwallowsPrimaryKeyCollision(): void
    {
        $exp = $this->clock->now()->getTimestamp() + 3600;
        $this->index->record('alice', 'jti-1', $exp);
        $this->index->record('alice', 'jti-1', $exp); // same jti again — idempotent

        $this->assertCount(1, $this->index->liveTokensFor('alice'));
    }

    // ------------------------------------- the table, not just what is listed

    private function rowCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM webcal_user_jti')?->fetchColumn();
    }

    /** @return list<string> */
    private function storedJtis(): array
    {
        $rows = $this->pdo->query('SELECT jti FROM webcal_user_jti ORDER BY jti')?->fetchAll(\PDO::FETCH_COLUMN);

        return \is_array($rows) ? array_values(array_map('strval', $rows)) : [];
    }

    public function testAnExpiredRowIsDeletedNotMerelyHiddenOnRead(): void
    {
        // liveTokensFor() filters on expires_at anyway, so an expired row that
        // is never purged looks exactly the same from outside -- which is why
        // the purge could be removed entirely without a failure. The table is
        // the only place the difference shows, and the class documents itself
        // as trimming opportunistically so it does not need a cron: without
        // that, this table grows by a row per login for the life of the
        // deployment.
        $now = $this->clock->now()->getTimestamp();
        $this->index->record('alice', 'live-jti', $now + 3600);
        $this->index->record('alice', 'dead-jti', $now - 10);
        self::assertSame(['dead-jti', 'live-jti'], $this->storedJtis(), 'both are on disk to begin with');

        $this->index->liveTokensFor('alice');

        self::assertSame(['live-jti'], $this->storedJtis());
    }

    public function testRecordingATokenSweepsTheExpiredOnesOut(): void
    {
        // record() purges before inserting, so the sweep happens on write as
        // well as on read -- a user who only ever logs in, and never lists
        // their sessions, still gets their old rows collected.
        $now = $this->clock->now()->getTimestamp();
        $this->index->record('alice', 'dead-jti', $now - 10);
        self::assertSame(1, $this->rowCount());

        $this->index->record('alice', 'fresh-jti', $now + 3600);

        self::assertSame(['fresh-jti'], $this->storedJtis());
    }

    public function testATokenExpiringExactlyNowIsSweptOut(): void
    {
        // The purge is `expires_at <= now`, so a token whose expiry has just
        // been reached is gone rather than lingering for one more sweep --
        // matching liveTokensFor()'s strictly-greater-than filter.
        $now = $this->clock->now()->getTimestamp();
        $this->index->record('alice', 'boundary-jti', $now);
        self::assertSame(1, $this->rowCount());

        $this->index->liveTokensFor('alice');

        self::assertSame([], $this->storedJtis());
    }

    public function testTheSweepLeavesOtherLoginsAlone(): void
    {
        $now = $this->clock->now()->getTimestamp();
        $this->index->record('alice', 'alice-live', $now + 3600);
        $this->index->record('bob', 'bob-live', $now + 3600);
        $this->index->record('bob', 'bob-dead', $now - 10);

        $this->index->liveTokensFor('alice');

        self::assertSame(['alice-live', 'bob-live'], $this->storedJtis());
    }

    // ------------------------------------------------ a database with no table

    /** @return iterable<string, array{\Closure(UserTokenIndex): void}> */
    public static function writesOnAnEmptyDatabase(): iterable
    {
        // Both deletes create the schema before running, because either can be
        // the first thing to touch the table -- a logout arriving before any
        // token was recorded through this process. Without it the DELETE hits
        // a missing table and the exception is not caught here.
        yield 'forget' => [static fn(UserTokenIndex $i): null => $i->forget('never-seen')];
        yield 'forgetAllFor' => [static fn(UserTokenIndex $i): null => $i->forgetAllFor('nobody')];
    }

    /** @param \Closure(UserTokenIndex): void $write */
    #[DataProvider('writesOnAnEmptyDatabase')]
    public function testAWriteOnAFreshDatabaseCreatesTheTableFirst(\Closure $write): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $index = new UserTokenIndex($pdo, $this->clock);

        $write($index);

        self::assertSame(
            'webcal_user_jti',
            $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='webcal_user_jti'")?->fetchColumn(),
        );
    }

    public function testTheExpiryIsReadAsAnIntOnAStringifyingDriver(): void
    {
        // MySQL's PDO returns expires_at as a string, and this value is handed
        // to Lexik's blocklist as a token payload's `exp`. Without the cast it
        // arrives as "1776254400", which any === against an int fails.
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, true);
        $index = new UserTokenIndex($pdo, $this->clock);

        $exp = $this->clock->now()->getTimestamp() + 3600;
        $index->record('alice', 'jti-1', $exp);

        self::assertSame([['jti' => 'jti-1', 'exp' => $exp]], $index->liveTokensFor('alice'));
    }
}
