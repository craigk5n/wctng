<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\CalDavSyncTokenRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The CalDAV sync-token override table.
 *
 * This file used to sit under tests/Integration, which is a directory the
 * mutation run does not execute -- and every other route into this class goes
 * through CoreCalendarBackend::getSyncToken(), which wraps the call in a
 * catch-all. So a broken CREATE TABLE, a missing ensureSchema(), or a token
 * that never moves were all invisible: the backend swallowed the exception and
 * fell back to its wall-clock token. Nothing here needs MySQL, so it belongs
 * in the suite that actually runs it.
 */
final class CalDavSyncTokenRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private CalDavSyncTokenRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->repo = new CalDavSyncTokenRepository($this->pdo);
    }

    public function testGetMissingReturnsNull(): void
    {
        $this->assertNull($this->repo->getOverride('alice'));
    }

    public function testBumpCreatesOverride(): void
    {
        $this->repo->bumpForUsers(['alice']);
        $this->assertNotNull($this->repo->getOverride('alice'));
        $this->assertGreaterThan(0, $this->repo->getOverride('alice'));
    }

    public function testBumpIsStrictlyMonotonic(): void
    {
        $this->repo->bumpForUsers(['alice']);
        $first = $this->repo->getOverride('alice');
        $this->repo->bumpForUsers(['alice']);
        $second = $this->repo->getOverride('alice');
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertGreaterThan($first, $second);
    }

    public function testBumpOnlyAffectsNamedUsers(): void
    {
        $this->repo->bumpForUsers(['alice']);
        $this->assertNotNull($this->repo->getOverride('alice'));
        $this->assertNull($this->repo->getOverride('bob'));
    }

    public function testBumpEmptyListIsNoOp(): void
    {
        $this->repo->bumpForUsers([]);
        $this->assertNull($this->repo->getOverride('alice'));
    }

    public function testBumpDeduplicatesRepeatedLogins(): void
    {
        // Passing alice twice in the same call should still result in a
        // single stable override value.
        $this->repo->bumpForUsers(['alice', 'alice', 'alice']);
        $this->assertNotNull($this->repo->getOverride('alice'));
    }

    public function testEnsureSchemaIdempotent(): void
    {
        $this->repo->ensureSchema();
        $this->repo->ensureSchema();
        $this->repo->bumpForUsers(['alice']);
        $this->assertNotNull($this->repo->getOverride('alice'));
    }

    // ------------------------------------------------ the value, not just "set"

    private const NOW = '2026-03-15T10:30:45+00:00';

    private function frozenRepo(?\PDO $pdo = null): CalDavSyncTokenRepository
    {
        return new CalDavSyncTokenRepository($pdo ?? $this->pdo, new MockClock(self::NOW));
    }

    private static function nowToken(): int
    {
        return (int) (new \DateTimeImmutable(self::NOW))->format('YmdHis');
    }

    public function testABumpLandsOneAboveTheClock(): void
    {
        // Nothing injected a clock, so every assertion here could only be
        // "greater than zero" or "greater than last time" -- which leaves the
        // arithmetic that produces the token unpinned.
        $this->frozenRepo()->bumpForUsers(['alice']);

        $this->assertSame(self::nowToken() + 1, $this->frozenRepo()->getOverride('alice'));
    }

    public function testARepeatedLoginIsBumpedOnceNotOncePerMention(): void
    {
        // The existing dedup case only asserts the override is not null, which
        // is true however many times the loop runs. Without array_unique() a
        // caller that lists a login three times advances the token three
        // steps -- and bumpForUsers() is called with whatever the caller
        // collected, duplicates included.
        $this->frozenRepo()->bumpForUsers(['alice', 'alice', 'alice']);

        $this->assertSame(self::nowToken() + 1, $this->frozenRepo()->getOverride('alice'));
    }

    public function testASecondBumpInTheSameSecondStillMovesForward(): void
    {
        // Two bumps within one clock tick have to differ, or a client that
        // polled between them sees no change and skips the sync.
        $repo = $this->frozenRepo();
        $repo->bumpForUsers(['alice']);
        $first = $repo->getOverride('alice');
        $repo->bumpForUsers(['alice']);

        $this->assertSame(self::nowToken() + 1, $first);
        $this->assertSame(self::nowToken() + 2, $repo->getOverride('alice'));
    }

    public function testEveryNamedLoginIsBumped(): void
    {
        $this->frozenRepo()->bumpForUsers(['alice', 'bob', 'carol']);

        $repo = $this->frozenRepo();
        foreach (['alice', 'bob', 'carol'] as $login) {
            $this->assertSame(self::nowToken() + 1, $repo->getOverride($login), $login);
        }
    }

    public function testTheOverrideIsReadAsAnIntOnAStringifyingDriver(): void
    {
        // MySQL's PDO returns the BIGINT as a string. Callers compare the
        // override with === against other ints, and CoreCalendarBackend feeds
        // it to max() alongside one.
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, true);

        $repo = $this->frozenRepo($pdo);
        $repo->bumpForUsers(['alice']);

        $this->assertSame(self::nowToken() + 1, $repo->getOverride('alice'));
    }
}
