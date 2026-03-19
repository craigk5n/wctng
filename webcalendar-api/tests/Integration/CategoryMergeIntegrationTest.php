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
    private function createCat(string $name): int
    {
        $nextId = $this->factory->getCategoryRepository()->nextId();
        $cat = new Category($nextId, 'admin', $name, '#3788d8');
        $this->factory->getCategoryRepository()->save($cat);
        return $nextId;
    }

    private function createEvent(string $title): int
    {
        $event = new Event(
            id: new EventId(0), uid: 'merge-' . bin2hex(random_bytes(4)) . '@test',
            name: $title, description: '', location: '',
            start: new \DateTimeImmutable('2026-07-01 10:00:00'),
            duration: 60, createdBy: 'admin', type: EventType::EVENT, access: AccessLevel::PUBLIC,
        );
        $this->factory->getEventService()->createEvent($event, $this->adminUser);
        $created = $this->factory->getEventRepository()->findByUid($event->uid());
        $this->assertNotNull($created);
        return $created->id()->value();
    }

    private function assignCat(int $eventId, int $catId): void
    {
        $this->factory->getCategoryRepository()->assignToEvent(new EventId($eventId), 'admin', [$catId]);
    }

    /** @return list<int> */
    private function getEventCatIds(int $eventId): array
    {
        $stmt = $this->pdo->prepare('SELECT cat_id FROM webcal_entry_categories WHERE cal_id = :eid');
        $stmt->execute(['eid' => $eventId]);
        $ids = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            if (\is_array($row)) {
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
}
