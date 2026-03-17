<?php

declare(strict_types=1);

namespace App\Tests\Unit\Share;

use App\Share\ShareTokenRepository;
use PHPUnit\Framework\TestCase;

final class ShareTokenRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private ShareTokenRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->repo = new ShareTokenRepository($this->pdo);
    }

    public function testCreateAndFindByToken(): void
    {
        $token = $this->repo->create('abc-123', 'alice', null);

        $this->assertSame('abc-123', $token->token());
        $this->assertSame('alice', $token->ownerLogin());
        $this->assertNull($token->expiresAt());
        $this->assertGreaterThan(0, $token->id());

        $found = $this->repo->findByToken('abc-123');
        $this->assertNotNull($found);
        $this->assertSame('alice', $found->ownerLogin());
    }

    public function testCreateWithExpiry(): void
    {
        $expires = '2026-12-31 23:59:59';
        $token = $this->repo->create('exp-token', 'bob', $expires);

        $this->assertSame($expires, $token->expiresAt());
        $this->assertFalse($token->isExpired());
    }

    public function testExpiredToken(): void
    {
        $token = $this->repo->create('old-token', 'alice', '2020-01-01 00:00:00');

        $this->assertTrue($token->isExpired());
    }

    public function testFindByOwner(): void
    {
        $this->repo->create('t1', 'alice', null);
        $this->repo->create('t2', 'alice', null);
        $this->repo->create('t3', 'bob', null);

        $aliceTokens = $this->repo->findByOwner('alice');
        $this->assertCount(2, $aliceTokens);

        $bobTokens = $this->repo->findByOwner('bob');
        $this->assertCount(1, $bobTokens);
    }

    public function testFindByTokenReturnsNullForMissing(): void
    {
        $this->assertNull($this->repo->findByToken('nonexistent'));
    }

    public function testDelete(): void
    {
        $this->repo->create('del-me', 'alice', null);

        $deleted = $this->repo->delete('del-me', 'alice');
        $this->assertTrue($deleted);

        $found = $this->repo->findByToken('del-me');
        $this->assertNull($found);
    }

    public function testDeleteWrongOwner(): void
    {
        $this->repo->create('not-yours', 'alice', null);

        $deleted = $this->repo->delete('not-yours', 'bob');
        $this->assertFalse($deleted);

        // Token should still exist
        $this->assertNotNull($this->repo->findByToken('not-yours'));
    }

    public function testToArray(): void
    {
        $token = $this->repo->create('arr-token', 'alice', '2026-06-01 12:00:00');
        $arr = $token->toArray();

        $this->assertSame('arr-token', $arr['token']);
        $this->assertSame('alice', $arr['owner_login']);
        $this->assertSame('2026-06-01 12:00:00', $arr['expires_at']);
        $this->assertArrayHasKey('id', $arr);
        $this->assertArrayHasKey('created_at', $arr);
    }
}
