<?php

declare(strict_types=1);

namespace App\Tests\Unit\CustomField;

use App\CustomField\CustomFieldDefinition;
use App\CustomField\CustomFieldRepository;
use PHPUnit\Framework\TestCase;

final class CustomFieldRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private CustomFieldRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->repo = new CustomFieldRepository($this->pdo);
    }

    public function testCreateAndFindAll(): void
    {
        $field = new CustomFieldDefinition(0, 'Department', 'text', false, 0, '');
        $id = $this->repo->save($field);

        $this->assertGreaterThan(0, $id);

        $all = $this->repo->findAll();
        $this->assertCount(1, $all);
        $this->assertSame('Department', $all[0]->name());
        $this->assertSame('text', $all[0]->fieldType());
    }

    public function testCreateSelectFieldWithOptions(): void
    {
        $options = json_encode(['Engineering', 'Marketing', 'Sales'], \JSON_THROW_ON_ERROR);
        $field = new CustomFieldDefinition(0, 'Team', 'select', true, 1, $options);
        $id = $this->repo->save($field);

        $found = $this->repo->findById($id);
        $this->assertNotNull($found);
        $this->assertSame('select', $found->fieldType());
        $this->assertTrue($found->isRequired());

        $arr = $found->toArray();
        $this->assertSame(['Engineering', 'Marketing', 'Sales'], $arr['options']);
    }

    public function testUpdate(): void
    {
        $field = new CustomFieldDefinition(0, 'Priority', 'number', false, 0, '');
        $id = $this->repo->save($field);

        $updated = new CustomFieldDefinition($id, 'Priority Level', 'number', true, 5, '');
        $this->repo->save($updated);

        $found = $this->repo->findById($id);
        $this->assertNotNull($found);
        $this->assertSame('Priority Level', $found->name());
        $this->assertTrue($found->isRequired());
        $this->assertSame(5, $found->sortOrder());
    }

    public function testDelete(): void
    {
        $field = new CustomFieldDefinition(0, 'Delete Me', 'text', false, 0, '');
        $id = $this->repo->save($field);

        $this->repo->delete($id);
        $this->assertNull($this->repo->findById($id));
    }

    public function testSortOrder(): void
    {
        $this->repo->save(new CustomFieldDefinition(0, 'Third', 'text', false, 3, ''));
        $this->repo->save(new CustomFieldDefinition(0, 'First', 'text', false, 1, ''));
        $this->repo->save(new CustomFieldDefinition(0, 'Second', 'text', false, 2, ''));

        $all = $this->repo->findAll();
        $this->assertSame('First', $all[0]->name());
        $this->assertSame('Second', $all[1]->name());
        $this->assertSame('Third', $all[2]->name());
    }
}
