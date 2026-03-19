<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use WebCalendar\Core\Domain\Entity\Category;

/**
 * Tests promoting personal categories to global and vice versa.
 * Uses delete + re-create pattern because webcalendar-core's save() uses
 * composite key (cat_id + cat_owner) which prevents owner changes via UPDATE.
 */
final class CategoryPromoteIntegrationTest extends IntegrationTestCase
{
    private function createPersonalCategory(string $name): int
    {
        $cat = new Category(0, 'alice', $name, '#3788d8');
        $this->factory->getCategoryService()->createCategory($cat, $this->normalUser);
        $found = $this->factory->getCategoryRepository()->findByName($name, 'alice');
        return $found !== null ? $found->id() : 0;
    }

    private function createGlobalCategory(string $name): int
    {
        $cat = new Category(0, null, $name, '#e53935');
        $this->factory->getCategoryService()->createCategory($cat, $this->adminUser);
        $found = $this->factory->getCategoryRepository()->findByName($name);
        return $found !== null ? $found->id() : 0;
    }

    public function testPromotePersonalToGlobal(): void
    {
        $catId = $this->createPersonalCategory('PromoteMe');

        $cat = $this->factory->getCategoryRepository()->findById($catId);
        $this->assertNotNull($cat);
        $this->assertSame('alice', $cat->owner());

        // Promote via delete + re-create (controller pattern)
        $this->factory->getCategoryRepository()->delete($catId);
        $promoted = new Category($catId, null, $cat->name(), $cat->color(), $cat->isEnabled());
        $this->factory->getCategoryRepository()->save($promoted);

        $updated = $this->factory->getCategoryRepository()->findById($catId);
        $this->assertNotNull($updated);
        $this->assertNull($updated->owner());
        $this->assertTrue($updated->isGlobal());
    }

    public function testDemoteGlobalToPersonal(): void
    {
        $catId = $this->createGlobalCategory('DemoteMe');

        $cat = $this->factory->getCategoryRepository()->findById($catId);
        $this->assertNotNull($cat);
        $this->assertTrue($cat->isGlobal());

        // Demote via delete + re-create
        $this->factory->getCategoryRepository()->delete($catId);
        $demoted = new Category($catId, 'admin', $cat->name(), $cat->color(), $cat->isEnabled());
        $this->factory->getCategoryRepository()->save($demoted);

        $updated = $this->factory->getCategoryRepository()->findById($catId);
        $this->assertNotNull($updated);
        $this->assertSame('admin', $updated->owner());
        $this->assertFalse($updated->isGlobal());
    }

    public function testPromoteIdempotent(): void
    {
        $catId = $this->createGlobalCategory('AlreadyGlobal');

        $cat = $this->factory->getCategoryRepository()->findById($catId);
        $this->assertNotNull($cat);

        // Promote already-global — no-op
        $this->factory->getCategoryService()->updateCategory($cat, $this->adminUser);

        $updated = $this->factory->getCategoryRepository()->findById($catId);
        $this->assertNotNull($updated);
        $this->assertTrue($updated->isGlobal());
    }
}
