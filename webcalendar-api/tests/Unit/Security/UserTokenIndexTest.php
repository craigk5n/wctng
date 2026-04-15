<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\UserTokenIndex;
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
}
