<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use WebCalendar\Core\Domain\Entity\Category;

final class CategoryIntegrationTest extends IntegrationTestCase
{
    public function testCreateGlobalCategory(): void
    {
        $catService = $this->factory->getCategoryService();
        $repo = $this->factory->getCategoryRepository();

        $nextId = $repo->nextId();
        $cat = new Category(id: $nextId, owner: null, name: 'Global Work', color: '#ff0000');
        $catService->createCategory($cat, $this->adminUser);

        $found = $repo->findById($nextId);
        $this->assertNotNull($found);
        $this->assertSame('Global Work', $found->name());
        $this->assertTrue($found->isGlobal());
    }

    public function testCreatePrivateCategory(): void
    {
        $catService = $this->factory->getCategoryService();
        $repo = $this->factory->getCategoryRepository();

        $nextId = $repo->nextId();
        $cat = new Category(id: $nextId, owner: 'alice', name: 'Alice Personal', color: '#00ff00');
        $catService->createCategory($cat, $this->normalUser);

        $found = $repo->findById($nextId);
        $this->assertNotNull($found);
        $this->assertSame('alice', $found->owner());
        $this->assertFalse($found->isGlobal());
    }

    public function testGetCategoriesForUserReturnsGlobalAndOwn(): void
    {
        $catService = $this->factory->getCategoryService();
        $repo = $this->factory->getCategoryRepository();

        // Create global
        $id1 = $repo->nextId();
        $catService->createCategory(new Category(id: $id1, owner: null, name: 'Global', color: '#aaa'), $this->adminUser);

        // Create alice's private
        $id2 = $repo->nextId();
        $catService->createCategory(new Category(id: $id2, owner: 'alice', name: 'Alice Cat', color: '#bbb'), $this->normalUser);

        // Create bob's private (should NOT appear for alice)
        $id3 = $repo->nextId();
        $bobUser = new \WebCalendar\Core\Domain\Entity\User('bob', 'Bob', 'Jones', 'bob@test.com', false, true);
        $this->factory->getUserService()->createUser($bobUser, $this->adminUser);
        $catService->createCategory(new Category(id: $id3, owner: 'bob', name: 'Bob Cat', color: '#ccc'), $bobUser);

        $aliceCats = $catService->getCategoriesForUser('alice');
        $names = array_map(fn($c) => $c->name(), $aliceCats);

        $this->assertContains('Global', $names);
        $this->assertContains('Alice Cat', $names);
        $this->assertNotContains('Bob Cat', $names);
    }
}
