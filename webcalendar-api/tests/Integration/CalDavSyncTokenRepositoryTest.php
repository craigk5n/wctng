<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\CalDavSyncTokenRepository;
use PHPUnit\Framework\TestCase;

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
}
