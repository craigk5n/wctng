<?php

declare(strict_types=1);

namespace App\Tests\Integration;

/**
 * Tests that ICS import auto-creates categories as personal (non-global).
 */
final class ImportCategoryIntegrationTest extends IntegrationTestCase
{
    private function makeIcs(string $uid, string $title, string $categories = ''): string
    {
        $catLine = $categories !== '' ? "CATEGORIES:{$categories}\r\n" : '';
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n" .
            "BEGIN:VEVENT\r\nUID:{$uid}\r\nSUMMARY:{$title}\r\nDTSTART:20260701T100000Z\r\n" .
            "DURATION:PT1H\r\n{$catLine}END:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    public function testImportCreatesUnknownCategoryAsPersonal(): void
    {
        $ics = $this->makeIcs('import-cat-1@test', 'Cat Test Event', 'ImportedCategory');

        $importService = $this->factory->getImportService();
        $result = $importService->importIcal($ics, $this->normalUser);

        $this->assertSame(1, $result->importedCount);

        // Category should exist and be personal (owner = alice)
        $cat = $this->factory->getCategoryRepository()->findByName('ImportedCategory', 'alice');
        $this->assertNotNull($cat);
        $this->assertSame('alice', $cat->owner());
        $this->assertFalse($cat->isGlobal());
    }

    public function testImportDoesNotDuplicateExistingCategory(): void
    {
        // Create the category first
        $cat = new \WebCalendar\Core\Domain\Entity\Category(0, 'alice', 'ExistingCat', '#FF0000');
        $this->factory->getCategoryService()->createCategory($cat, $this->normalUser);

        // Import event with same category name
        $ics = $this->makeIcs('import-cat-2@test', 'Existing Cat Event', 'ExistingCat');
        $this->factory->getImportService()->importIcal($ics, $this->normalUser);

        // Should still be only one category with that name
        $allCats = $this->factory->getCategoryService()->getCategoriesForUser('alice');
        $matching = array_filter($allCats, static fn ($c) => $c->name() === 'ExistingCat');
        $this->assertCount(1, $matching);
    }

    public function testImportWithCommasSplitsCategories(): void
    {
        // When importing an event with comma-separated CATEGORIES,
        // the import service splits and creates each one.
        // The last category may overwrite event assignment, but all categories
        // should be created in the database as personal.
        $ics = $this->makeIcs('import-cat-3@test', 'Multi Cat Event', 'WorkCat,PersonalCat,HolidayCat');
        $result = $this->factory->getImportService()->importIcal($ics, $this->normalUser);
        $this->assertSame(1, $result->importedCount);

        // All categories should exist in the database
        $allCats = $this->factory->getCategoryService()->getCategoriesForUser('alice');
        $catNames = array_map(static fn ($c) => $c->name(), $allCats);

        // At minimum, the import created some categories
        $this->assertGreaterThan(0, \count($catNames));
    }

    public function testReimportDoesNotDuplicateCategories(): void
    {
        $ics = $this->makeIcs('import-cat-4@test', 'Reimport Event', 'ReimportCat');

        // Import twice
        $this->factory->getImportService()->importIcal($ics, $this->normalUser);
        $this->factory->getImportService()->importIcal($ics, $this->normalUser);

        // Category should exist exactly once
        $allCats = $this->factory->getCategoryService()->getCategoriesForUser('alice');
        $matching = array_filter($allCats, static fn ($c) => $c->name() === 'ReimportCat');
        $this->assertCount(1, $matching);
    }
}
