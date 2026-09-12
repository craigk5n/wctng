<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Api\CustomFieldController;
use App\CustomField\CustomFieldDefinition;
use App\CustomField\CustomFieldRepository;
use App\Security\WebCalendarUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use WebCalendar\Core\Application\Service\EventService;
use WebCalendar\Core\Application\Service\SiteExtraService;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\Repository\UserRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Infrastructure\Persistence\PdoSiteExtraRepository;

/**
 * Administrator-defined extra fields, and the values events carry in them.
 *
 * Nothing executed a line of it. The definitions are administrator-only CRUD,
 * but the values are not: two routes take an event id straight out of the URL,
 * and the write one begins by deleting every value the event already had.
 */
final class CustomFieldControllerTest extends TestCase
{
    private \PDO $pdo;
    private CustomFieldRepository $fields;
    private SiteExtraService $extras;
    private ?Event $event = null;

    #[\Override]
    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE webcal_site_extras (
                cal_id INT NOT NULL, cal_name VARCHAR(50) NOT NULL,
                cal_type INT NOT NULL DEFAULT 0, cal_date INT, cal_remind INT, cal_data TEXT
            )',
        );

        $this->fields = new CustomFieldRepository($this->pdo);
        $this->extras = new SiteExtraService(new PdoSiteExtraRepository($this->pdo));
        $this->event = self::event('alice');
    }

    private static function event(string $createdBy): Event
    {
        return new Event(
            id: new EventId(41),
            uid: 'e41@x',
            name: 'Quarterly review',
            description: '',
            location: '',
            start: new \DateTimeImmutable('2026-10-01 10:00:00'),
            duration: 60,
            createdBy: $createdBy,
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
        );
    }

    private function controller(): CustomFieldController
    {
        $events = $this->createMock(EventRepositoryInterface::class);
        $events->method('findById')->willReturnCallback(fn(): ?Event => $this->event);

        return new CustomFieldController(
            $this->extras,
            $this->fields,
            new EventService($events, $this->createMock(UserRepositoryInterface::class)),
        );
    }

    private static function user(string $login = 'alice', bool $admin = false): WebCalendarUser
    {
        return new WebCalendarUser(new User($login, ucfirst($login), 'S', "{$login}@x.com", $admin, true), null);
    }

    private static function request(mixed $body): Request
    {
        $content = \is_string($body) ? $body : json_encode($body, \JSON_THROW_ON_ERROR);

        return Request::create('/api/v2/admin/custom-fields', 'POST', [], [], [], [], $content);
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

    // ------------------------------------------------------- the definitions

    #[DataProvider('definitionCallsWithoutAccess')]
    public function testManagingDefinitionsNeedsAnAdministrator(\Closure $call, ?WebCalendarUser $caller): void
    {
        $this->assertSame(403, $call($this->controller(), $caller)->getStatusCode());
        $this->assertSame([], $this->fields->findAll());
    }

    /** @return iterable<string, array{\Closure, WebCalendarUser|null}> */
    public static function definitionCallsWithoutAccess(): iterable
    {
        $calls = [
            'list' => static fn(CustomFieldController $c, ?WebCalendarUser $u): Response => $c->list($u),
            'create' => static fn(CustomFieldController $c, ?WebCalendarUser $u): Response
                => $c->create(self::request(['name' => 'Cost centre']), $u),
            'update' => static fn(CustomFieldController $c, ?WebCalendarUser $u): Response
                => $c->update(1, self::request(['name' => 'x']), $u),
            'delete' => static fn(CustomFieldController $c, ?WebCalendarUser $u): Response => $c->delete(1, $u),
        ];

        foreach ($calls as $name => $call) {
            yield "{$name}, anonymous" => [$call, null];
            yield "{$name}, not an admin" => [$call, self::user('bob')];
        }
    }

    public function testTheEventFormCanReadTheDefinitionsWithoutSigningIn(): void
    {
        // The form needs to know what to draw before anyone has signed in.
        $this->fields->save(new CustomFieldDefinition(0, 'Cost centre', 'text', true, 1, ''));

        $listed = self::payload($this->controller()->listPublic())['data'];

        $this->assertCount(1, $listed);
        $this->assertSame('Cost centre', $listed[0]['name']);
    }

    public function testCreatingADefinitionStoresIt(): void
    {
        $response = $this->controller()->create(
            self::request([
                'name' => 'Cost centre',
                'field_type' => 'select',
                'required' => true,
                'sort_order' => 3,
                'options' => ['CC-1', 'CC-2'],
            ]),
            self::user(admin: true),
        );

        $this->assertSame(201, $response->getStatusCode());
        $stored = $this->fields->findAll()[0];
        $this->assertSame('Cost centre', $stored->name());
        $this->assertSame('select', $stored->fieldType());
        $this->assertTrue($stored->isRequired());
        $this->assertSame(3, $stored->sortOrder());
        $this->assertSame('["CC-1","CC-2"]', $stored->options());
    }

    public function testADefinitionDefaultsToAnOptionalTextField(): void
    {
        $this->controller()->create(self::request(['name' => 'Notes']), self::user(admin: true));

        $stored = $this->fields->findAll()[0];
        $this->assertSame('text', $stored->fieldType());
        $this->assertFalse($stored->isRequired());
        $this->assertSame(0, $stored->sortOrder());
        $this->assertSame('', $stored->options());
    }

    #[DataProvider('definitionsThatAreNotOne')]
    public function testCreatingRefusesADefinitionItCannotStore(mixed $body): void
    {
        $response = $this->controller()->create(self::request($body), self::user(admin: true));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $this->fields->findAll());
    }

    /** @return iterable<string, array{mixed}> */
    public static function definitionsThatAreNotOne(): iterable
    {
        yield 'no name' => [['field_type' => 'text']];
        yield 'an empty name' => [['name' => '']];
        yield 'a name of spaces' => [['name' => '   ']];
        yield 'a numeric name' => [['name' => 42]];
        // The column is a VARCHAR(60).
        yield 'a name longer than the column' => [['name' => str_repeat('a', 61)]];
        yield 'a field type nothing can draw' => [['name' => 'X', 'field_type' => 'wysiwyg']];
        yield 'a field type longer than the column' => [['name' => 'X', 'field_type' => str_repeat('t', 21)]];
        yield 'not an object' => ['"just a string"'];
        yield 'not json at all' => ['<html>'];
        yield 'empty body' => [''];
    }

    #[DataProvider('everyFieldTypeTheFormCanDraw')]
    public function testEachFieldTypeTheFormOffersIsAccepted(string $type): void
    {
        $response = $this->controller()->create(
            self::request(['name' => 'F', 'field_type' => $type]),
            self::user(admin: true),
        );

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame($type, $this->fields->findAll()[0]->fieldType());
    }

    /** @return iterable<string, array{string}> */
    public static function everyFieldTypeTheFormCanDraw(): iterable
    {
        foreach (['text', 'number', 'date', 'select', 'checkbox'] as $type) {
            yield $type => [$type];
        }
    }

    public function testANameOfExactlyTheColumnWidthIsAccepted(): void
    {
        $name = str_repeat('a', 60);

        $response = $this->controller()->create(self::request(['name' => $name]), self::user(admin: true));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame($name, $this->fields->findAll()[0]->name());
    }

    public function testANameIsMeasuredInCharactersNotBytes(): void
    {
        // The column counts characters, so sixty accented letters fit it and
        // take a hundred and twenty bytes; measured in bytes this would be
        // refused for being exactly the right size.
        $name = str_repeat('é', 60);

        $response = $this->controller()->create(self::request(['name' => $name]), self::user(admin: true));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame($name, $this->fields->findAll()[0]->name());
    }

    public function testARefusedFieldTypeSaysWhichOnesThereAre(): void
    {
        $response = $this->controller()->create(
            self::request(['name' => 'X', 'field_type' => 'wysiwyg']),
            self::user(admin: true),
        );

        $this->assertSame(
            'Field type must be one of: text, number, date, select, checkbox',
            self::payload($response)['error']['message'],
            'naming them is the only useful thing a 400 here can say',
        );
    }

    public function testASortOrderWrittenAsDigitsIsStoredAsANumber(): void
    {
        // CustomFieldDefinition takes an int under strict types, so a numeric
        // string reaching it is a TypeError rather than a field.
        $response = $this->controller()->create(
            self::request(['name' => 'X', 'sort_order' => '3']),
            self::user(admin: true),
        );

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(3, $this->fields->findAll()[0]->sortOrder());
    }

    public function testTwoDefinitionsCannotShareAName(): void
    {
        // The column is UNIQUE, so the second one reached the database and
        // came back a 500 rather than saying what was wrong.
        $this->fields->save(new CustomFieldDefinition(0, 'Cost centre', 'text', false, 0, ''));

        $response = $this->controller()->create(
            self::request(['name' => 'Cost centre']),
            self::user(admin: true),
        );

        $this->assertSame(409, $response->getStatusCode());
        $this->assertCount(1, $this->fields->findAll());
    }

    public function testUpdatingChangesOnlyWhatWasSent(): void
    {
        $id = $this->fields->save(new CustomFieldDefinition(0, 'Cost centre', 'select', true, 2, '["a"]'));

        $response = $this->controller()->update($id, self::request(['name' => 'Cost code']), self::user(admin: true));

        $this->assertSame(200, $response->getStatusCode());
        $stored = $this->fields->findById($id);
        $this->assertNotNull($stored);
        $this->assertSame('Cost code', $stored->name());
        $this->assertSame('select', $stored->fieldType());
        $this->assertTrue($stored->isRequired());
        $this->assertSame(2, $stored->sortOrder());
        $this->assertSame('["a"]', $stored->options());
    }

    public function testUpdatingSomethingThatIsNotThereIsNotFound(): void
    {
        $this->assertSame(404, $this->controller()->update(99, self::request(['name' => 'x']), self::user(admin: true))->getStatusCode());
    }

    /**
     * Update is a partial one -- every field defaults to what is stored -- so
     * only a value that was actually sent and cannot be used is refused.
     */
    #[DataProvider('changesThatCannotBeStored')]
    public function testUpdatingRefusesAChangeItCannotStore(mixed $body): void
    {
        $id = $this->fields->save(new CustomFieldDefinition(0, 'Cost centre', 'text', false, 0, ''));

        $response = $this->controller()->update($id, self::request($body), self::user(admin: true));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Cost centre', $this->fields->findById($id)?->name());
    }

    /** @return iterable<string, array{mixed}> */
    public static function changesThatCannotBeStored(): iterable
    {
        yield 'renaming to nothing' => [['name' => '']];
        yield 'renaming to spaces' => [['name' => '   ']];
        yield 'a name longer than the column' => [['name' => str_repeat('a', 61)]];
        yield 'a field type nothing can draw' => [['field_type' => 'wysiwyg']];
        yield 'a field type longer than the column' => [['field_type' => str_repeat('t', 21)]];
    }

    #[DataProvider('changesThatFallBack')]
    public function testUpdatingKeepsWhatIsStoredWhenNothingUsableArrived(mixed $body): void
    {
        // A field left out, or sent as something it cannot be, keeps its
        // value rather than refusing the rest of the change.
        $id = $this->fields->save(new CustomFieldDefinition(0, 'Cost centre', 'select', true, 2, '["a"]'));

        $response = $this->controller()->update($id, self::request($body), self::user(admin: true));

        $this->assertSame(200, $response->getStatusCode());
        $stored = $this->fields->findById($id);
        $this->assertNotNull($stored);
        $this->assertSame('Cost centre', $stored->name());
        $this->assertSame('select', $stored->fieldType());
        $this->assertTrue($stored->isRequired());
        $this->assertSame(2, $stored->sortOrder());
        $this->assertSame('["a"]', $stored->options());
    }

    /** @return iterable<string, array{mixed}> */
    public static function changesThatFallBack(): iterable
    {
        yield 'nothing sent' => [[]];
        yield 'only a sort order that is not a number' => [['sort_order' => 'first']];
        yield 'a numeric name' => [['name' => 42]];
        yield 'an array field type' => [['field_type' => ['text']]];
        yield 'options that are not a list' => [['options' => 'a,b']];
        yield 'not an object' => ['"just a string"'];
        yield 'not json at all' => ['<html>'];
        yield 'empty body' => [''];
    }

    public function testDeletingRemovesTheDefinition(): void
    {
        $id = $this->fields->save(new CustomFieldDefinition(0, 'Cost centre', 'text', false, 0, ''));

        $response = $this->controller()->delete($id, self::user(admin: true));

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame([], $this->fields->findAll());
    }

    public function testDeletingSomethingThatIsNotThereIsNotFound(): void
    {
        $this->assertSame(404, $this->controller()->delete(99, self::user(admin: true))->getStatusCode());
    }

    // ------------------------------------------------------------ the values

    private static function valuesRequest(mixed $body): Request
    {
        $content = \is_string($body) ? $body : json_encode($body, \JSON_THROW_ON_ERROR);

        return Request::create('/api/v2/events/41/custom-fields', 'PUT', [], [], [], [], $content);
    }

    public function testReadingAndWritingValuesNeedsASession(): void
    {
        $this->assertSame(401, $this->controller()->saveValues(41, self::valuesRequest(['a' => 'b']), null)->getStatusCode());
        $this->assertSame(401, $this->controller()->getValues(41, null)->getStatusCode());
    }

    public function testTheOrganiserCanSetTheValuesOnTheirOwnEvent(): void
    {
        $response = $this->controller()->saveValues(
            41,
            self::valuesRequest(['Cost centre' => 'CC-9000']),
            self::user('alice'),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['Cost centre' => 'CC-9000'], $this->extras->getExtrasForEvent(41));
        $this->assertSame(
            ['event_id' => 41, 'fields' => ['Cost centre' => 'CC-9000']],
            self::payload($response)['data'],
            'the reply has to say which event it wrote to',
        );
    }

    public function testASortOrderChangeWrittenAsDigitsIsStoredAsANumber(): void
    {
        $id = $this->fields->save(new CustomFieldDefinition(0, 'Cost centre', 'text', false, 0, ''));

        $response = $this->controller()->update($id, self::request(['sort_order' => '7']), self::user(admin: true));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(7, $this->fields->findById($id)?->sortOrder());
    }

    public function testAnAdministratorCanSetThemOnSomebodyElsesEvent(): void
    {
        $response = $this->controller()->saveValues(
            41,
            self::valuesRequest(['Cost centre' => 'CC-9000']),
            self::user('sysop', admin: true),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['Cost centre' => 'CC-9000'], $this->extras->getExtrasForEvent(41));
    }

    /**
     * The event id comes out of the URL and nothing checked whose event it
     * was. Worse than a write: saveForEvent() deletes every value the event
     * already had before inserting, so an empty body aimed at somebody else's
     * event id erased what was there.
     */
    public function testSomebodyElsesEventCannotBeWrittenTo(): void
    {
        $this->extras->saveExtrasForEvent(41, ['Cost centre' => 'CC-9000', 'Client' => 'Acme']);

        $response = $this->controller()->saveValues(41, self::valuesRequest([]), self::user('mallory'));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(
            ['Cost centre' => 'CC-9000', 'Client' => 'Acme'],
            $this->extras->getExtrasForEvent(41),
            'the values must still be there',
        );
    }

    public function testWritingToAnEventThatIsNotThereIsNotFound(): void
    {
        $this->event = null;

        $response = $this->controller()->saveValues(99, self::valuesRequest(['a' => 'b']), self::user('alice'));

        $this->assertSame(404, $response->getStatusCode());
    }

    #[DataProvider('valueBodiesThatAreNotObjects')]
    public function testABodyThatIsNotAnObjectIsRefused(string $body): void
    {
        $this->extras->saveExtrasForEvent(41, ['Cost centre' => 'CC-9000']);

        $response = $this->controller()->saveValues(41, self::valuesRequest($body), self::user('alice'));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(['Cost centre' => 'CC-9000'], $this->extras->getExtrasForEvent(41));
    }

    /** @return iterable<string, array{string}> */
    public static function valueBodiesThatAreNotObjects(): iterable
    {
        yield 'a bare string' => ['"just a string"'];
        yield 'not json at all' => ['<html>'];
        yield 'empty' => [''];
    }

    public function testSettingTheValuesReplacesWhatWasThere(): void
    {
        $this->extras->saveExtrasForEvent(41, ['Cost centre' => 'CC-9000', 'Client' => 'Acme']);

        $this->controller()->saveValues(41, self::valuesRequest(['Client' => 'Globex']), self::user('alice'));

        $this->assertSame(['Client' => 'Globex'], $this->extras->getExtrasForEvent(41));
    }

    public function testTheValuesComeBack(): void
    {
        $this->extras->saveExtrasForEvent(41, ['Cost centre' => 'CC-9000']);

        $data = self::payload($this->controller()->getValues(41, self::user('alice')))['data'];

        $this->assertSame(['Cost centre' => 'CC-9000'], $data);
    }

    public function testAnEventWithNoValuesHasNone(): void
    {
        $this->assertSame([], self::payload($this->controller()->getValues(41, self::user('alice')))['data']);
    }
}
