<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\ExtParticipantRepository;

final class ExtParticipantRepositoryTest extends IntegrationTestCase
{
    private ExtParticipantRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new ExtParticipantRepository($this->pdo);
    }

    public function testFindForMissingEventReturnsEmpty(): void
    {
        $this->assertSame([], $this->repo->findForEvent(999));
    }

    public function testSaveAndFind(): void
    {
        $this->repo->saveForEvent(1, [
            ['name' => 'Bob External', 'email' => 'bob@external.com'],
            ['name' => 'Alice Guest', 'email' => null],
        ]);

        $result = $this->repo->findForEvent(1);
        $this->assertCount(2, $result);
        // Alphabetical by cal_fullname
        $this->assertSame('Alice Guest', $result[0]['name']);
        $this->assertNull($result[0]['email']);
        $this->assertSame('Bob External', $result[1]['name']);
        $this->assertSame('bob@external.com', $result[1]['email']);
    }

    public function testSaveReplacesExisting(): void
    {
        $this->repo->saveForEvent(1, [['name' => 'Old', 'email' => 'old@ex.com']]);
        $this->repo->saveForEvent(1, [['name' => 'New', 'email' => 'new@ex.com']]);

        $result = $this->repo->findForEvent(1);
        $this->assertCount(1, $result);
        $this->assertSame('New', $result[0]['name']);
    }

    public function testSaveEmptyClearsAll(): void
    {
        $this->repo->saveForEvent(1, [['name' => 'X', 'email' => null]]);
        $this->repo->saveForEvent(1, []);
        $this->assertSame([], $this->repo->findForEvent(1));
    }

    public function testDeleteForEvent(): void
    {
        $this->repo->saveForEvent(1, [['name' => 'X', 'email' => null]]);
        $this->repo->deleteForEvent(1);
        $this->assertSame([], $this->repo->findForEvent(1));
    }

    public function testDeduplicatesByName(): void
    {
        $this->repo->saveForEvent(1, [
            ['name' => 'Bob', 'email' => 'first@ex.com'],
            ['name' => 'Bob', 'email' => 'second@ex.com'],
        ]);

        $result = $this->repo->findForEvent(1);
        $this->assertCount(1, $result);
        // First one wins
        $this->assertSame('first@ex.com', $result[0]['email']);
    }

    public function testFindForEventsBatch(): void
    {
        $this->repo->saveForEvent(1, [['name' => 'A1', 'email' => null]]);
        $this->repo->saveForEvent(2, [['name' => 'A2', 'email' => null], ['name' => 'B2', 'email' => null]]);
        // Event 3 has nothing

        $batch = $this->repo->findForEvents([1, 2, 3]);
        $this->assertArrayHasKey(1, $batch);
        $this->assertArrayHasKey(2, $batch);
        $this->assertArrayNotHasKey(3, $batch);
        $this->assertCount(1, $batch[1]);
        $this->assertCount(2, $batch[2]);
    }

    public function testFindForEventsEmptyList(): void
    {
        $this->assertSame([], $this->repo->findForEvents([]));
    }

    public function testScopedToEventId(): void
    {
        $this->repo->saveForEvent(1, [['name' => 'E1', 'email' => null]]);
        $this->repo->saveForEvent(2, [['name' => 'E2', 'email' => null]]);

        $this->assertCount(1, $this->repo->findForEvent(1));
        $this->assertCount(1, $this->repo->findForEvent(2));
        $this->assertSame('E1', $this->repo->findForEvent(1)[0]['name']);
    }
}
