<?php

declare(strict_types=1);

namespace App\Tests\Unit\View;

use App\View\SavedViewRepository;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testCreateWithCategoryIds(): void
    {
        $id = $this->repo->create('alice', 'Filtered View', ['alice'], false, [1, 3, 5]);
        $this->assertGreaterThan(0, $id);

        $views = $this->repo->findByOwner('alice');
        $this->assertCount(1, $views);
        $this->assertSame([1, 3, 5], $views[0]['category_ids']);
    }

    public function testCreateWithoutCategoryIdsDefaultsToEmpty(): void
    {
        $id = $this->repo->create('alice', 'No Filter', ['alice']);
        $views = $this->repo->findByOwner('alice');
        $this->assertSame([], $views[0]['category_ids']);
    }

    public function testCategoryIdsRoundTrip(): void
    {
        $this->repo->create('alice', 'View A', ['alice'], false, [10, 20]);
        $this->repo->create('alice', 'View B', ['bob'], false, []);

        $views = $this->repo->findByOwner('alice');
        $this->assertCount(2, $views);

        $viewA = $views[0]['name'] === 'View A' ? $views[0] : $views[1];
        $viewB = $views[0]['name'] === 'View B' ? $views[0] : $views[1];

        $this->assertSame([10, 20], $viewA['category_ids']);
        $this->assertSame([], $viewB['category_ids']);
    }

    // ------------------------------------------------- every mapped key, read

    public function testAViewIsMappedWithEveryKeyItsCallersRead(): void
    {
        // The existing cases read name, user_logins and category_ids. The id,
        // the owner and the global flag were never asserted -- and the id is
        // what delete() is called with, so a ternary swapped to return 0 makes
        // every view undeletable.
        $id = $this->repo->create('alice', 'Team View', ['alice', 'bob'], true, [4, 8]);

        $views = $this->repo->findByOwner('alice');

        $this->assertCount(1, $views);
        $this->assertSame(
            [
                'id' => $id,
                'name' => 'Team View',
                'user_logins' => ['alice', 'bob'],
                'is_global' => true,
                'owner' => 'alice',
                'category_ids' => [4, 8],
            ],
            $views[0],
        );
    }

    /** @return iterable<string, array{bool}> */
    public static function globalStates(): iterable
    {
        // is_global is stored as 'Y'/'N' and compared with ===. A view marked
        // global is visible to every user, so getting this wrong either leaks
        // one person's view to everybody or hides a shared one.
        yield 'global' => [true];
        yield 'private' => [false];
    }

    #[DataProvider('globalStates')]
    public function testTheGlobalFlagSurvivesTheRoundTrip(bool $isGlobal): void
    {
        $this->repo->create('alice', 'A View', ['alice'], $isGlobal);

        $this->assertSame($isGlobal, $this->repo->findByOwner('alice')[0]['is_global']);
    }

    public function testAGlobalViewIsVisibleToSomebodyElse(): void
    {
        // The query is "own views OR global ones", which is the only reason
        // the flag is read at all.
        $this->repo->create('alice', 'Everyones View', ['alice'], true);
        $this->repo->create('alice', 'Alices View', ['alice'], false);

        $bobSees = array_map(
            static fn(array $v): string => $v['name'],
            $this->repo->findByOwner('bob'),
        );

        $this->assertSame(['Everyones View'], $bobSees);
    }

    public function testTheIdIsReadAsAnIntOnAStringifyingDriver(): void
    {
        // MySQL's PDO returns the id as a string; delete() takes an int and
        // callers compare ids with ===.
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, true);
        $repo = new SavedViewRepository($pdo);

        $id = $repo->create('alice', 'Stringy', ['alice'], false, [2, 4]);
        $view = $repo->findByOwner('alice')[0];

        $this->assertSame($id, $view['id']);
        $this->assertSame([2, 4], $view['category_ids']);
    }

    // --------------------------------------- what a stored column may contain

    /** @return iterable<string, array{string, list<string>}> */
    public static function storedLoginPayloads(): iterable
    {
        // user_logins is a JSON column, so anything could be in it: hand-edited
        // rows, an older format, a partial write. The decoder keeps the strings
        // and reindexes what is left, because callers treat the result as a
        // list and json_encode would otherwise turn a gap into an object.
        yield 'a plain list' => ['["alice","bob"]', ['alice', 'bob']];
        yield 'numbers mixed in' => ['["alice",7,"bob"]', ['alice', 'bob']];
        yield 'nulls mixed in' => ['["alice",null,"bob"]', ['alice', 'bob']];
        yield 'nested arrays' => ['["alice",["bob"]]', ['alice']];
        yield 'all rejects' => ['[1,2,3]', []];
        yield 'an empty list' => ['[]', []];
        yield 'not a list at all' => ['"alice"', []];
        yield 'not json' => ['alice,bob', []];
    }

    /** @param list<string> $expected */
    #[DataProvider('storedLoginPayloads')]
    public function testStoredLoginsAreDecodedToAListOfStrings(string $stored, array $expected): void
    {
        $id = $this->repo->create('alice', 'Decoded', ['placeholder']);
        $this->pdo->prepare('UPDATE saved_views SET user_logins = :v WHERE id = :id')
            ->execute(['v' => $stored, 'id' => $id]);

        $logins = $this->repo->findByOwner('alice')[0]['user_logins'];

        $this->assertSame($expected, $logins);
        $this->assertSame(array_values($logins), $logins, 'the result is a list, not a gapped array');
    }

    /** @return iterable<string, array{string, list<int>}> */
    public static function storedCategoryPayloads(): iterable
    {
        yield 'ints' => ['[1,2]', [1, 2]];
        yield 'numeric strings' => ['["3","4"]', [3, 4]];
        yield 'mixed with junk' => ['[5,"six",7]', [5, 7]];
        yield 'not json' => ['1,2', []];
    }

    /** @param list<int> $expected */
    #[DataProvider('storedCategoryPayloads')]
    public function testStoredCategoryIdsAreDecodedToInts(string $stored, array $expected): void
    {
        // Numeric strings have to come back as ints: these are compared with
        // === against event category ids when filtering.
        $id = $this->repo->create('alice', 'Cats', ['alice']);
        $this->pdo->prepare('UPDATE saved_views SET category_ids = :v WHERE id = :id')
            ->execute(['v' => $stored, 'id' => $id]);

        $this->assertSame($expected, $this->repo->findByOwner('alice')[0]['category_ids']);
    }

    // ------------------------------------------------------- the table itself

    public function testAReadOnAFreshDatabaseCreatesTheTableFirst(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $this->assertSame([], (new SavedViewRepository($pdo))->findByOwner('alice'));
    }

    public function testAnOlderTableWithoutTheNewerColumnsIsUpgraded(): void
    {
        // ensureTable() probes for is_global and category_ids and adds them if
        // a pre-upgrade table is missing them. Both probes were unexercised,
        // because every test here starts from a table this version created.
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            'CREATE TABLE saved_views ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'owner_login VARCHAR(60) NOT NULL, '
            . 'name VARCHAR(100) NOT NULL, '
            . 'user_logins TEXT NOT NULL'
            . ')',
        );
        $pdo->exec("INSERT INTO saved_views (owner_login, name, user_logins) VALUES ('alice', 'Legacy', '[\"alice\"]')");

        $repo = new SavedViewRepository($pdo);
        $views = $repo->findByOwner('alice');

        $this->assertCount(1, $views);
        $this->assertSame('Legacy', $views[0]['name']);
        $this->assertFalse($views[0]['is_global'], 'a row from before the column defaults to private');
        $this->assertSame([], $views[0]['category_ids']);

        // Reading proves nothing about category_ids on its own: it is only
        // ever fetched by SELECT *, so a missing column reads back as the same
        // empty list the upgrade produces. Writing to it is what tells the two
        // apart -- and writing is what a user does next.
        $newId = $repo->create('alice', 'Post Upgrade', ['alice'], true, [11, 22]);
        $upgraded = array_values(array_filter(
            $repo->findByOwner('alice'),
            static fn(array $v): bool => $v['id'] === $newId,
        ));

        $this->assertCount(1, $upgraded);
        $this->assertSame([11, 22], $upgraded[0]['category_ids']);
        $this->assertTrue($upgraded[0]['is_global']);
    }
}
