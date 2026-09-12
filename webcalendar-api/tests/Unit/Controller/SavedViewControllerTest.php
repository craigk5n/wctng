<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Api\SavedViewController;
use App\Security\WebCalendarUser;
use App\View\SavedViewRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use WebCalendar\Core\Domain\Entity\User;

/**
 * Saved calendar views.
 *
 * Nothing executed a line of it. A view is a name plus two lists, all three
 * taken from the request body and none of them checked -- and the two lists
 * are filtered on the way back out, so what the route answered after a write
 * was not always what a later read returned.
 */
final class SavedViewControllerTest extends TestCase
{
    private \PDO $pdo;
    private SavedViewRepository $repo;

    #[\Override]
    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->repo = new SavedViewRepository($this->pdo);
    }

    private function controller(?SavedViewRepository $repo = null): SavedViewController
    {
        return new SavedViewController($repo ?? $this->repo);
    }

    /** A repository over a database where nothing has created the table yet. */
    private function untouchedRepository(): SavedViewRepository
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return new SavedViewRepository($pdo);
    }

    private static function user(string $login = 'alice', bool $admin = false): WebCalendarUser
    {
        return new WebCalendarUser(new User($login, ucfirst($login), 'S', "{$login}@x.com", $admin, true), null);
    }

    private static function request(mixed $body): Request
    {
        $content = \is_string($body) ? $body : json_encode($body, \JSON_THROW_ON_ERROR);

        return Request::create('/api/v2/views', 'POST', [], [], [], [], $content);
    }

    /** @return array<string, mixed> */
    private static function payload(Response $response): array
    {
        $body = $response->getContent();
        self::assertIsString($body);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /** @return array<string, mixed> */
    private function storedView(string $owner = 'alice'): array
    {
        $views = $this->repo->findByOwner($owner);
        self::assertCount(1, $views);

        return $views[0];
    }

    // ------------------------------------------------------------------ guards

    #[DataProvider('anonymousCalls')]
    public function testEveryRouteNeedsASession(\Closure $call): void
    {
        $this->assertSame(401, $call($this->controller())->getStatusCode());
    }

    /** @return iterable<string, array{\Closure}> */
    public static function anonymousCalls(): iterable
    {
        yield 'list' => [static fn(SavedViewController $c): Response => $c->list(null)];
        yield 'create' => [static fn(SavedViewController $c): Response => $c->create(self::request(['name' => 'X']), null)];
        yield 'update' => [static fn(SavedViewController $c): Response => $c->update(1, self::request(['name' => 'X']), null)];
        yield 'delete' => [static fn(SavedViewController $c): Response => $c->delete(1, null)];
    }

    // ------------------------------------------------------------------- list

    public function testTheListHoldsYourOwnViewsAndEverybodysGlobalOnes(): void
    {
        $this->repo->create('alice', 'Mine', ['bob'], false, [1]);
        $this->repo->create('bob', 'Bobs', ['bob'], false, []);
        $this->repo->create('sysop', 'Everyones', [], true, []);

        $names = array_column(self::payload($this->controller()->list(self::user('alice')))['data'], 'name');

        sort($names);
        $this->assertSame(['Everyones', 'Mine'], $names);
    }

    // ----------------------------------------------------------------- create

    public function testCreateStoresTheViewAndSaysWhatItStored(): void
    {
        $response = $this->controller()->create(
            self::request(['name' => 'Engineering', 'user_logins' => ['alice', 'bob'], 'category_ids' => [3, 7]]),
            self::user('alice'),
        );

        $this->assertSame(201, $response->getStatusCode());
        $answered = self::payload($response)['data'];

        $stored = $this->storedView();
        $this->assertSame('Engineering', $stored['name']);
        $this->assertSame(['alice', 'bob'], $stored['user_logins']);
        $this->assertSame([3, 7], $stored['category_ids']);
        $this->assertFalse($stored['is_global']);

        // What a write answers has to be what the next read returns.
        $this->assertSame($stored['id'], $answered['id']);
        $this->assertSame($stored['name'], $answered['name']);
        $this->assertSame($stored['user_logins'], $answered['user_logins']);
        $this->assertSame($stored['category_ids'], $answered['category_ids']);
        $this->assertSame($stored['is_global'], $answered['is_global']);
    }

    public function testAViewCanHoldNobodyAndNoCategories(): void
    {
        $response = $this->controller()->create(self::request(['name' => 'Empty']), self::user('alice'));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame([], $this->storedView()['user_logins']);
        $this->assertSame([], $this->storedView()['category_ids']);
    }

    #[DataProvider('bodiesThatAreNotAView')]
    public function testCreateRefusesABodyItCannotStore(mixed $body): void
    {
        $response = $this->controller()->create(self::request($body), self::user('alice'));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $this->repo->findByOwner('alice'), 'nothing may be stored for a rejected body');
    }

    /** @return iterable<string, array{mixed}> */
    public static function bodiesThatAreNotAView(): iterable
    {
        yield 'no name' => [['user_logins' => ['alice']]];
        yield 'empty name' => [['name' => '']];
        yield 'a name of spaces' => [['name' => '   ']];
        yield 'a numeric name' => [['name' => 42]];
        yield 'an array name' => [['name' => ['Engineering']]];
        // The column is a VARCHAR(100).
        yield 'a name longer than the column' => [['name' => str_repeat('a', 101)]];
        yield 'logins that are not a list' => [['name' => 'X', 'user_logins' => 'alice']];
        yield 'a login that is not a name' => [['name' => 'X', 'user_logins' => ['alice', 42]]];
        yield 'a login that is a list' => [['name' => 'X', 'user_logins' => [['alice']]]];
        yield 'categories that are not a list' => [['name' => 'X', 'category_ids' => '3']];
        yield 'a category that is not a number' => [['name' => 'X', 'category_ids' => [3, 'seven']]];
        yield 'not an object' => ['"just a string"'];
        yield 'not json at all' => ['<html>'];
        yield 'empty body' => [''];
    }

    public function testANameOfExactlyTheColumnWidthIsAccepted(): void
    {
        $name = str_repeat('a', 100);

        $response = $this->controller()->create(self::request(['name' => $name]), self::user('alice'));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame($name, $this->storedView()['name']);
    }

    public function testANameIsMeasuredInCharactersNotBytes(): void
    {
        // The column counts characters, so a hundred accented letters fit it
        // and take two hundred bytes; measured in bytes this would be refused
        // for being exactly the right size.
        $name = str_repeat('é', 100);

        $response = $this->controller()->create(self::request(['name' => $name]), self::user('alice'));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame($name, $this->storedView()['name']);
    }

    public function testACategoryIdWrittenAsDigitsComesBackAsANumber(): void
    {
        // The read side takes anything numeric and casts it, so the write side
        // has to as well -- otherwise the response says "7" where the next
        // read says 7.
        $response = $this->controller()->create(
            self::request(['name' => 'V', 'category_ids' => [3, '7']]),
            self::user('alice'),
        );

        $this->assertSame([3, 7], self::payload($response)['data']['category_ids']);
        $this->assertSame([3, 7], $this->storedView()['category_ids']);
    }

    public function testANameIsTrimmedBeforeItIsStored(): void
    {
        $this->controller()->create(self::request(['name' => '  Engineering  ']), self::user('alice'));

        $this->assertSame('Engineering', $this->storedView()['name']);
    }

    // ------------------------------------------------------------- global views

    public function testOnlyAnAdministratorCanMakeAViewEverybodys(): void
    {
        $this->controller()->create(
            self::request(['name' => 'Mine', 'is_global' => true]),
            self::user('alice'),
        );

        $this->assertFalse($this->storedView()['is_global'], 'an ordinary user asking for global does not get it');
    }

    public function testAnAdministratorCanMakeAViewEverybodys(): void
    {
        $this->controller()->create(
            self::request(['name' => 'Everyones', 'is_global' => true]),
            self::user('sysop', admin: true),
        );

        $this->assertTrue($this->storedView('sysop')['is_global']);
    }

    #[DataProvider('thingsThatAreNotTrue')]
    public function testOnlyTheWordTrueMakesAViewGlobal(mixed $value): void
    {
        $this->controller()->create(
            self::request(['name' => 'Everyones', 'is_global' => $value]),
            self::user('sysop', admin: true),
        );

        $this->assertFalse($this->storedView('sysop')['is_global']);
    }

    /**
     * Asking and being allowed are two separate conditions, and a view becomes
     * everybody's only when both hold.
     *
     * @param array<string, mixed> $body
     */
    #[DataProvider('askingAndBeingAllowed')]
    public function testAViewIsGlobalOnlyWhenItIsAskedForBySomebodyWhoMay(
        array $body,
        bool $admin,
        bool $expected,
    ): void {
        $login = $admin ? 'sysop' : 'alice';

        $this->controller()->create(self::request(['name' => 'V'] + $body), self::user($login, admin: $admin));
        $this->assertSame($expected, $this->storedView($login)['is_global'], 'on create');

        $id = $this->repo->create('carol', 'Theirs', [], false, []);
        $this->controller()->update($id, self::request(['name' => 'V'] + $body), self::user('carol', admin: $admin));
        $this->assertSame($expected, $this->repo->findByOwner('carol')[0]['is_global'], 'on update');
    }

    /** @return iterable<string, array{array<string, mixed>, bool, bool}> */
    public static function askingAndBeingAllowed(): iterable
    {
        yield 'an admin asking' => [['is_global' => true], true, true];
        yield 'an admin not asking' => [[], true, false];
        yield 'an ordinary user asking' => [['is_global' => true], false, false];
        yield 'an ordinary user not asking' => [[], false, false];
    }

    /** @return iterable<string, array{mixed}> */
    public static function thingsThatAreNotTrue(): iterable
    {
        yield 'false' => [false];
        yield 'the word' => ['true'];
        yield 'one' => [1];
        yield 'nothing' => [null];
    }

    // ----------------------------------------------------------------- update

    public function testUpdateChangesYourOwnView(): void
    {
        $id = $this->repo->create('alice', 'Old', ['bob'], false, [1]);

        $response = $this->controller()->update(
            $id,
            self::request(['name' => 'New', 'user_logins' => ['carol'], 'category_ids' => [9]]),
            self::user('alice'),
        );

        $this->assertSame(200, $response->getStatusCode());
        $stored = $this->storedView();
        $this->assertSame('New', $stored['name']);
        $this->assertSame(['carol'], $stored['user_logins']);
        $this->assertSame([9], $stored['category_ids']);
    }

    public function testUpdateSaysWhatItStored(): void
    {
        $id = $this->repo->create('alice', 'Old', ['bob'], false, [1]);

        $answered = self::payload($this->controller()->update(
            $id,
            self::request(['name' => 'New', 'user_logins' => ['carol'], 'category_ids' => [9]]),
            self::user('alice'),
        ))['data'];

        $stored = $this->storedView();
        $this->assertSame($stored['id'], $answered['id']);
        $this->assertSame($stored['name'], $answered['name']);
        $this->assertSame($stored['user_logins'], $answered['user_logins']);
        $this->assertSame($stored['category_ids'], $answered['category_ids']);
        $this->assertSame($stored['is_global'], $answered['is_global']);
    }

    public function testUpdateLeavesSomebodyElsesViewAlone(): void
    {
        $id = $this->repo->create('bob', 'Bobs', ['bob'], false, []);

        $response = $this->controller()->update($id, self::request(['name' => 'Mine now']), self::user('alice'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Bobs', $this->storedView('bob')['name']);
    }

    #[DataProvider('bodiesThatAreNotAView')]
    public function testUpdateRefusesABodyItCannotStore(mixed $body): void
    {
        $id = $this->repo->create('alice', 'Old', ['bob'], false, [1]);

        $response = $this->controller()->update($id, self::request($body), self::user('alice'));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Old', $this->storedView()['name'], 'a rejected body changes nothing');
    }

    // ----------------------------------------------------------------- delete

    public function testDeleteRemovesYourOwnView(): void
    {
        $id = $this->repo->create('alice', 'Mine', [], false, []);

        $response = $this->controller()->delete($id, self::user('alice'));

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame([], $this->repo->findByOwner('alice'));
    }

    public function testDeleteLeavesSomebodyElsesViewAlone(): void
    {
        $id = $this->repo->create('bob', 'Bobs', [], false, []);

        $this->assertSame(404, $this->controller()->delete($id, self::user('alice'))->getStatusCode());
        $this->assertCount(1, $this->repo->findByOwner('bob'));
    }

    // --------------------------------------------------- a database nobody has used

    /**
     * Only the reads and the insert create the table, so the first change or
     * removal after a deploy hit a table that was not there yet and raised
     * "no such table" instead of reporting the view missing.
     */
    #[DataProvider('writesToAnUntouchedDatabase')]
    public function testAWriteOnAFreshDatabaseReportsTheViewMissing(\Closure $call): void
    {
        $controller = $this->controller($this->untouchedRepository());

        $this->assertSame(404, $call($controller)->getStatusCode());
    }

    /** @return iterable<string, array{\Closure}> */
    public static function writesToAnUntouchedDatabase(): iterable
    {
        yield 'update' => [static fn(SavedViewController $c): Response
            => $c->update(1, self::request(['name' => 'X']), self::user('alice'))];
        yield 'delete' => [static fn(SavedViewController $c): Response => $c->delete(1, self::user('alice'))];
    }
}
