<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Api\AssistantController;
use App\Security\WebCalendarUser;
use App\Service\CoreServiceFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use WebCalendar\Core\Domain\Entity\User;

/**
 * Who is allowed to act for whom.
 *
 * Nothing had executed a line of this controller. Two of its three routes say
 * "You can only manage your own assistants"; the third says nothing at all,
 * and the body of the one that writes is read through an @var annotation that
 * promises a string the client never has to send.
 */
final class AssistantControllerTest extends TestCase
{
    private CoreServiceFactory $factory;
    private AssistantController $controller;

    #[\Override]
    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $schemaPath = realpath(__DIR__ . '/../../../vendor/craigk5n/webcalendar-core/src/Infrastructure/Persistence/sqlite-schema.sql');
        self::assertIsString($schemaPath);
        $schema = file_get_contents($schemaPath);
        self::assertIsString($schema);
        /** @var string[] $stmts */
        $stmts = preg_split('/;\s*\n/', (string) preg_replace('/--[^\n]*/', '', $schema)) ?? [];
        foreach ($stmts as $s) {
            if (trim($s) !== '') {
                try {
                    $pdo->exec(trim($s));
                } catch (\PDOException) {
                }
            }
        }

        $this->factory = new CoreServiceFactory($pdo, 'test_secret');
        $admin = self::coreUser('admin', admin: true);
        $this->factory->getUserService()->createUser($admin, $admin);
        $this->factory->getUserService()->createUser(self::coreUser('alice'), $admin);
        $this->factory->getUserService()->createUser(self::coreUser('bob'), $admin);

        $this->controller = new AssistantController($this->factory->getAssistantService());
    }

    private static function coreUser(string $login, bool $admin = false): User
    {
        return new User($login, 'F', 'L', "{$login}@x.com", $admin, true);
    }

    private static function actor(string $login, bool $admin = false): WebCalendarUser
    {
        return new WebCalendarUser(self::coreUser($login, $admin), null);
    }

    /** @param array<string, mixed>|string $body */
    private function add(string $login, array|string $body, ?WebCalendarUser $actor): Response
    {
        $json = \is_string($body) ? $body : json_encode($body, \JSON_THROW_ON_ERROR);

        return $this->controller->add($login, Request::create('/', 'POST', content: $json), $actor);
    }

    /** @return array<string, mixed> */
    private static function payload(Response $response): array
    {
        $content = $response->getContent();
        self::assertIsString($content);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private static function message(Response $response): string
    {
        /** @var array{error?: array{message?: string}} $p */
        $p = self::payload($response);

        return $p['error']['message'] ?? '';
    }

    // ------------------------------------------------------------------ auth

    public function testAnAnonymousCallerIsTurnedAwayFromEveryRoute(): void
    {
        self::assertSame(401, $this->controller->list('alice', null)->getStatusCode());
        self::assertSame(401, $this->add('alice', ['assistant' => 'bob'], null)->getStatusCode());
        self::assertSame(401, $this->controller->remove('alice', 'bob', null)->getStatusCode());
    }

    /** @return iterable<string, array{string}> */
    public static function someoneElsesList(): iterable
    {
        yield 'a stranger' => ['carol'];
        yield 'the assistant themselves' => ['bob'];
    }

    #[DataProvider('someoneElsesList')]
    public function testOnlyTheBossOrAnAdminCanAddAnAssistant(string $caller): void
    {
        $response = $this->add('alice', ['assistant' => 'bob'], self::actor($caller));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('You can only manage your own assistants', self::message($response));
        self::assertSame([], $this->factory->getAssistantService()->getAssistantsForBoss('alice'));
    }

    #[DataProvider('someoneElsesList')]
    public function testOnlyTheBossOrAnAdminCanRemoveAnAssistant(string $caller): void
    {
        $this->factory->getAssistantService()->assignAssistant('alice', 'bob');

        $response = $this->controller->remove('alice', 'bob', self::actor($caller));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(['bob'], $this->factory->getAssistantService()->getAssistantsForBoss('alice'));
    }

    public function testAnAdminCanManageSomebodyElsesAssistants(): void
    {
        self::assertSame(201, $this->add('alice', ['assistant' => 'bob'], self::actor('admin', admin: true))->getStatusCode());
        self::assertSame(['bob'], $this->factory->getAssistantService()->getAssistantsForBoss('alice'));

        self::assertSame(204, $this->controller->remove('alice', 'bob', self::actor('admin', admin: true))->getStatusCode());
        self::assertSame([], $this->factory->getAssistantService()->getAssistantsForBoss('alice'));
    }

    #[DataProvider('someoneElsesList')]
    public function testOnlyTheBossOrAnAdminCanReadWhoActsForThem(string $caller): void
    {
        // add() and remove() both refuse this. list() hands the whole
        // delegation graph -- who works for whom -- to any account that asks.
        $this->factory->getAssistantService()->assignAssistant('alice', 'bob');

        $response = $this->controller->list('alice', self::actor($caller));

        self::assertSame(403, $response->getStatusCode(), $caller . ' read alice\'s assistants');
    }

    public function testABossSeesTheirOwnAssistantsAndBosses(): void
    {
        $service = $this->factory->getAssistantService();
        $service->assignAssistant('alice', 'bob');
        $service->assignAssistant('admin', 'alice');

        $payload = self::payload($this->controller->list('alice', self::actor('alice')));

        /** @var array{data: array{assistants: list<array{login: string}>, bosses: list<array{login: string}>}} $payload */
        self::assertSame([['login' => 'bob']], $payload['data']['assistants']);
        self::assertSame([['login' => 'admin']], $payload['data']['bosses']);
    }

    // ------------------------------------------------------- what gets stored

    public function testTheAssistantFieldIsRequired(): void
    {
        $response = $this->add('alice', [], self::actor('alice'));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('Missing required field: assistant', self::message($response));
    }

    /** @return iterable<string, array{mixed}> */
    public static function assistantsThatAreNotLogins(): iterable
    {
        yield 'a space' => [' '];
        yield 'a tab' => ["\t"];
        yield 'a number' => [42];
        yield 'an object' => [['login' => 'bob']];
        yield 'a boolean' => [true];
        yield 'null' => [null];
    }

    #[DataProvider('assistantsThatAreNotLogins')]
    public function testSomethingThatIsNotALoginIsRefusedRatherThanThrown(mixed $assistant): void
    {
        // The @var says string. A whitespace-only name reaches the entity,
        // which throws; anything that is not a string at all reaches a typed
        // parameter, which throws too. Either way a 500 leaves a route that
        // answers in JSON.
        $response = $this->add('alice', ['assistant' => $assistant], self::actor('alice'));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([], $this->factory->getAssistantService()->getAssistantsForBoss('alice'));
    }

    public function testABodyThatIsNotJsonIsRefused(): void
    {
        // The message matters as well as the status: a body that is not JSON
        // decodes to null, whose 'assistant' offset is also null, so without
        // its own guard this arrives as "you left the field out" instead.
        $response = $this->add('alice', 'not json at all', self::actor('alice'));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('Invalid JSON body', self::message($response));
    }

    /** @return iterable<string, array{string}> */
    public static function loginsThatExactlyFitTheColumn(): iterable
    {
        yield 'sixty characters' => [str_repeat('a', 60)];
        // Sixty accented characters are sixty characters and a hundred and
        // twenty bytes. MySQL counts VARCHAR(60) in characters, not bytes,
        // so this fits and counting bytes here would wrongly refuse it.
        yield 'sixty accented characters' => [str_repeat("\u{e9}", 60)];
    }

    #[DataProvider('loginsThatExactlyFitTheColumn')]
    public function testALoginThatExactlyFillsTheColumnIsAccepted(string $login): void
    {
        $response = $this->add('alice', ['assistant' => $login], self::actor('alice'));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame([$login], $this->factory->getAssistantService()->getAssistantsForBoss('alice'));
    }

    public function testALoginTooLongForTheColumnIsRefused(): void
    {
        // cal_assistant is VARCHAR(60). SQLite shrugs; MySQL in strict mode
        // refuses the row, so this is a 500 in the deployment that matters
        // and a silently truncated one in the deployment that does not.
        $response = $this->add('alice', ['assistant' => str_repeat('a', 61)], self::actor('alice'));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([], $this->factory->getAssistantService()->getAssistantsForBoss('alice'));
    }

    public function testAddingAnAssistantAnswersWithWhatItStored(): void
    {
        $response = $this->add('alice', ['assistant' => 'bob'], self::actor('alice'));

        self::assertSame(201, $response->getStatusCode());

        /** @var array{data: array{boss: string, assistant: string}} $payload */
        $payload = self::payload($response);
        self::assertSame('alice', $payload['data']['boss']);
        self::assertSame('bob', $payload['data']['assistant']);
        self::assertSame(['bob'], $this->factory->getAssistantService()->getAssistantsForBoss('alice'));
    }

    public function testRemovingAnAssistantTakesOnlyThatOne(): void
    {
        $service = $this->factory->getAssistantService();
        $service->assignAssistant('alice', 'bob');
        $service->assignAssistant('alice', 'admin');

        self::assertSame(204, $this->controller->remove('alice', 'bob', self::actor('alice'))->getStatusCode());
        self::assertSame(['admin'], $service->getAssistantsForBoss('alice'));
    }

    public function testRemovingSomebodyWhoIsNotAnAssistantIsStillFine(): void
    {
        self::assertSame(204, $this->controller->remove('alice', 'bob', self::actor('alice'))->getStatusCode());
    }
}
