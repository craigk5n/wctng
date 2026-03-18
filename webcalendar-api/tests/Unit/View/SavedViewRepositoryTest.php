<?php

declare(strict_types=1);

namespace App\Tests\Unit\View;

use App\View\SavedViewRepository;
use PHPUnit\Framework\TestCase;

final class SavedViewRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private SavedViewRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->repo = new SavedViewRepository($this->pdo);
    }

    public function testCreateAndFind(): void
    {
        $id = $this->repo->create('alice', 'Team View', ['alice', 'bob', 'carol']);
        $this->assertGreaterThan(0, $id);

        $views = $this->repo->findByOwner('alice');
        $this->assertCount(1, $views);
        $this->assertSame('Team View', $views[0]['name']);
        $this->assertSame(['alice', 'bob', 'carol'], $views[0]['user_logins']);
    }

    public function testFindByOwnerReturnsOnlyOwned(): void
    {
        $this->repo->create('alice', 'Alice View', ['alice']);
        $this->repo->create('bob', 'Bob View', ['bob']);

        $this->assertCount(1, $this->repo->findByOwner('alice'));
        $this->assertCount(1, $this->repo->findByOwner('bob'));
    }

    public function testDelete(): void
    {
        $id = $this->repo->create('alice', 'Delete Me', ['alice']);
        $this->assertTrue($this->repo->delete($id, 'alice'));
        $this->assertCount(0, $this->repo->findByOwner('alice'));
    }

    public function testDeleteWrongOwner(): void
    {
        $id = $this->repo->create('alice', 'Not Yours', ['alice']);
        $this->assertFalse($this->repo->delete($id, 'bob'));
        $this->assertCount(1, $this->repo->findByOwner('alice'));
    }
}
