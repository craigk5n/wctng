<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use WebCalendar\Core\Domain\Entity\Category;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;

final class CategoryMergeIntegrationTest extends IntegrationTestCase
{
    private function createCat(string $name, ?string $owner = 'admin'): int
    {
        $nextId = $this->factory->getCategoryRepository()->nextId();
        $cat = new Category($nextId, $owner, $name, '#3788d8');
        $this->factory->getCategoryRepository()->save($cat);
        return $nextId;
    }

    private function createEvent(string $title, string $createdBy = 'admin'): int
    {
        $actor = $createdBy === 'admin' ? $this->adminUser : $this->normalUser;
        $event = new Event(
            id: new EventId(0),
            uid: 'merge-' . bin2hex(random_bytes(4)) . '@test',
            name: $title,
            description: '',
            location: '',
            start: new \DateTimeImmutable('2026-07-01 10:00:00'),
            duration: 60,
            createdBy: $createdBy,
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
        );
        $this->factory->getEventService()->createEvent($event, $actor);
        $created = $this->factory->getEventRepository()->findByUid($event->uid());
        $this->assertNotNull($created);
        return $created->id()->value();
    }

    private function assignCat(int $eventId, int $catId, string $userLogin = 'admin'): void
    {
        $this->factory->getCategoryRepository()->assignToEvent(new EventId($eventId), $userLogin, [$catId]);
    }

    /** @return list<int> */
    private function getEventCatIds(int $eventId): array
    {
        $stmt = $this->pdo->prepare('SELECT cat_id FROM webcal_entry_categories WHERE cal_id = :eid');
        $stmt->execute(['eid' => $eventId]);
        $ids = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            if (\is_array($row) && is_numeric($row['cat_id'] ?? null)) {
                $ids[] = (int) $row['cat_id'];
            }
        }
        return $ids;
    }

    public function testMergeReassignsEvents(): void
    {
        $sourceId = $this->createCat('Holiday');
        $targetId = $this->createCat('Holidays');
        $eventId = $this->createEvent('Christmas');
        $this->assignCat($eventId, $sourceId);

        // Verify assignment
        $before = $this->getEventCatIds($eventId);
        $this->assertContains($sourceId, $before);

        // Merge
        $this->factory->getCategoryRepository()->reassignEvents($sourceId, $targetId, 'admin');

        $after = $this->getEventCatIds($eventId);
        $this->assertContains($targetId, $after);
        $this->assertNotContains($sourceId, $after);
    }

    public function testMergeDeletesSource(): void
    {
        $sourceId = $this->createCat('OldCat');
        $targetId = $this->createCat('NewCat');

        $this->factory->getCategoryRepository()->reassignEvents($sourceId, $targetId, 'admin');
        $this->factory->getCategoryRepository()->delete($sourceId);

        // Source gone, target still exists
        $allCats = $this->factory->getCategoryService()->getCategoriesForUser('admin');
        $names = array_map(static fn ($c) => $c->name(), $allCats);
        $this->assertNotContains('OldCat', $names);
        $this->assertContains('NewCat', $names);
    }

    public function testMergePreservesTargetProperties(): void
    {
        $sourceId = $this->createCat('SourceCat');
        $targetId = $this->createCat('TargetCat');

        $this->factory->getCategoryRepository()->reassignEvents($sourceId, $targetId, 'admin');

        $target = $this->factory->getCategoryRepository()->findByName('TargetCat', 'admin');
        $this->assertNotNull($target);
        $this->assertSame('TargetCat', $target->name());
    }

    public function testMergeHandlesDuplicateAssignments(): void
    {
        $sourceId = $this->createCat('DupSrc');
        $targetId = $this->createCat('DupTgt');
        $eventId = $this->createEvent('DupEvent');

        // Assign to BOTH
        $this->assignCat($eventId, $sourceId);
        $this->assignCat($eventId, $targetId);

        // Merge should not error on duplicate
        $this->factory->getCategoryRepository()->reassignEvents($sourceId, $targetId, 'admin');

        $after = $this->getEventCatIds($eventId);
        $this->assertContains($targetId, $after);
    }

    /**
     * Documents the core 4.3 semantic change: `reassignEvents($src, $tgt, $user)`
     * now only moves junction rows whose `cat_owner = $user`. Before 4.3 it
     * moved every row at `cat_id = $src` regardless of owner. This test
     * pins the current behavior so we notice if it changes again.
     */
    public function testReassignEventsIsScopedToCallerOwner(): void
    {
        $sourceId = $this->createCat('Legacy', owner: null); // global
        $targetId = $this->createCat('Modern', owner: null);

        $adminEvent = $this->createEvent('AdminEvent', 'admin');
        $aliceEvent = $this->createEvent('AliceEvent', 'alice');

        $this->assignCat($adminEvent, $sourceId, 'admin');
        $this->assignCat($aliceEvent, $sourceId, 'alice');

        // Call reassignEvents with only 'admin' — pre-4.3 this moved both
        // rows; post-4.3 it leaves Alice's assignment behind.
        $this->factory->getCategoryRepository()->reassignEvents($sourceId, $targetId, 'admin');

        $this->assertContains($targetId, $this->getEventCatIds($adminEvent));
        $this->assertNotContains($sourceId, $this->getEventCatIds($adminEvent));

        // Alice's assignment is UNTOUCHED by the single admin call.
        $this->assertContains($sourceId, $this->getEventCatIds($aliceEvent));
        $this->assertNotContains($targetId, $this->getEventCatIds($aliceEvent));
    }

    /**
     * Proves the CategoryController admin-merge workaround: enumerate
     * distinct `cat_owner`s on the source junction rows and call
     * reassignEvents once per owner. Every user's assignments should
     * migrate to the target category, and the source category should
     * then be safely deletable via deleteByCompositeKey without leaving
     * dangling junction rows.
     */
    public function testAdminMergeLoopsPerOwnerAcrossUsers(): void
    {
        $sourceId = $this->createCat('Meetings', owner: null); // global
        $targetId = $this->createCat('Meeting', owner: null);

        $adminEvent = $this->createEvent('AdminStandup', 'admin');
        $aliceEvent = $this->createEvent('Alice1on1', 'alice');

        $this->assignCat($adminEvent, $sourceId, 'admin');
        $this->assignCat($aliceEvent, $sourceId, 'alice');

        // Mirror CategoryController::merge's loop: enumerate distinct
        // owners of junction rows at the source, then reassign per owner.
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT cat_owner FROM webcal_entry_categories WHERE cat_id = :cat_id'
        );
        $stmt->execute(['cat_id' => $sourceId]);
        $owners = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            if (\is_array($row) && \is_string($row['cat_owner'] ?? null)) {
                $owners[] = $row['cat_owner'];
            }
        }
        $this->assertEqualsCanonicalizing(['admin', 'alice'], $owners);

        foreach ($owners as $owner) {
            $this->factory->getCategoryRepository()->reassignEvents($sourceId, $targetId, $owner);
        }

        $this->factory->getCategoryRepository()->deleteByCompositeKey($sourceId, '');

        $this->assertContains($targetId, $this->getEventCatIds($adminEvent));
        $this->assertContains($targetId, $this->getEventCatIds($aliceEvent));
        $this->assertNotContains($sourceId, $this->getEventCatIds($adminEvent));
        $this->assertNotContains($sourceId, $this->getEventCatIds($aliceEvent));

        // Source category row is gone.
        $this->assertNull($this->factory->getCategoryRepository()->findByCompositeKey($sourceId, ''));
    }
}
