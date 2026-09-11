<?php

declare(strict_types=1);

namespace App\Tests\Unit\CustomField;

use App\CustomField\CustomFieldDefinition;
use App\CustomField\CustomFieldRepository;
use PHPUnit\Framework\Attributes\DataProvider;
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

    // ------------------------------------------------- every column, read back

    private const OPTIONS = '["Engineering","Marketing","Sales"]';

    private function fullyPopulated(int $id = 0, bool $required = true): CustomFieldDefinition
    {
        return new CustomFieldDefinition($id, 'Team', 'select', $required, 7, self::OPTIONS);
    }

    public function testEveryColumnSurvivesTheRoundTrip(): void
    {
        // The existing cases each read back two or three accessors, and never
        // the id -- so the narrowing ternary behind it could have its arms
        // swapped and hand every definition an id of 0, which is the value
        // save() reads as "insert a new one".
        $id = $this->repo->save($this->fullyPopulated());

        $found = $this->repo->findById($id);

        $this->assertNotNull($found);
        $this->assertSame($id, $found->id());
        $this->assertSame('Team', $found->name());
        $this->assertSame('select', $found->fieldType());
        $this->assertTrue($found->isRequired());
        $this->assertSame(7, $found->sortOrder());
        $this->assertSame(self::OPTIONS, $found->options());
    }

    public function testFindAllReadsTheSameColumnsAsFindById(): void
    {
        $id = $this->repo->save($this->fullyPopulated());

        $all = $this->repo->findAll();

        $this->assertCount(1, $all);
        $this->assertSame($this->repo->findById($id)?->toArray(), $all[0]->toArray());
        $this->assertSame($id, $all[0]->id());
    }

    public function testTheRowIsReadAsIntsAndBoolsOnAStringifyingDriver(): void
    {
        // MySQL's PDO returns every column as a string, which is what the casts
        // on id, required and sort_order are for. On SQLite they look like
        // no-ops, so dropping them would leave callers comparing "7" with 7 --
        // and sort_order in particular is what the admin UI reorders on.
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, true);
        $repo = new CustomFieldRepository($pdo);

        $id = $repo->save($this->fullyPopulated());
        $found = $repo->findById($id);

        $this->assertNotNull($found);
        $this->assertSame($id, $found->id());
        $this->assertSame(7, $found->sortOrder());
        $this->assertTrue($found->isRequired());
    }

    // ------------------------------------------------------ the required flag

    /** @return iterable<string, array{bool}> */
    public static function requiredStates(): iterable
    {
        yield 'required' => [true];
        yield 'optional' => [false];
    }

    /** @return iterable<string, array{bool, int}> */
    public static function requiredStatesAndTheirStoredValue(): iterable
    {
        yield 'required is stored as one' => [true, 1];
        yield 'optional is stored as zero' => [false, 0];
    }

    #[DataProvider('requiredStatesAndTheirStoredValue')]
    public function testTheFlagIsStoredAsOneOrZeroAndNothingElse(bool $required, int $stored): void
    {
        // The round-trip tests below pass for any falsey-on-read value, since
        // mapRow only asks whether the column equals 1 -- so the column could
        // be written as -1 and this class would never notice. It is an
        // INTEGER NOT NULL standing in for a boolean, and what is in it is
        // what anything reading the table directly will see.
        $insertedId = $this->repo->save($this->fullyPopulated(required: $required));
        self::assertSame($stored, $this->storedRequired($insertedId));

        // And again down the update path, which writes the same expression.
        $this->repo->save($this->fullyPopulated($insertedId, $required));
        self::assertSame($stored, $this->storedRequired($insertedId));
    }

    private function storedRequired(int $id): int
    {
        $stmt = $this->pdo->prepare('SELECT required FROM custom_field_definitions WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return (int) $stmt->fetchColumn();
    }

    #[DataProvider('requiredStates')]
    public function testTheRequiredFlagSurvivesAnInsert(bool $required): void
    {
        $id = $this->repo->save($this->fullyPopulated(required: $required));

        $this->assertSame($required, $this->repo->findById($id)?->isRequired());
    }

    #[DataProvider('requiredStates')]
    public function testTheRequiredFlagSurvivesAnUpdate(bool $required): void
    {
        // Only the optional-to-required direction was covered, so the flag
        // could have been written as a constant on update: either making every
        // edited field mandatory, or quietly dropping the requirement from one
        // that had it.
        $id = $this->repo->save($this->fullyPopulated(required: !$required));

        $this->repo->save($this->fullyPopulated($id, $required));

        $this->assertSame($required, $this->repo->findById($id)?->isRequired());
    }

    // ------------------------------------------------------- the table itself

    /** @return iterable<string, array{\Closure(CustomFieldRepository): mixed}> */
    public static function readsOnAnEmptyDatabase(): iterable
    {
        // Both read paths create the table before querying it. Without that,
        // the first request after a deploy fails with "no such table" rather
        // than reporting that no custom fields are configured.
        yield 'findAll' => [static fn(CustomFieldRepository $r): mixed => $r->findAll()];
        yield 'findById' => [static fn(CustomFieldRepository $r): mixed => $r->findById(1)];
    }

    /** @param \Closure(CustomFieldRepository): mixed $read */
    #[DataProvider('readsOnAnEmptyDatabase')]
    public function testAReadOnAFreshDatabaseCreatesTheTableFirst(\Closure $read): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $result = $read(new CustomFieldRepository($pdo));

        $this->assertTrue($result === null || $result === []);
    }
}
