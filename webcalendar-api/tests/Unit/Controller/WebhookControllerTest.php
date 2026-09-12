<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Api\WebhookController;
use App\Security\OutboundUrlValidator;
use App\Security\WebCalendarUser;
use App\Webhook\WebhookRepository;
use App\Webhook\WebhookSubscription;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use WebCalendar\Core\Domain\Entity\User;

/**
 * Administering webhook subscriptions.
 *
 * Nothing executed a line of this controller. A webhook is a URL this server
 * will fetch, and a secret a receiver authenticates deliveries with, so two
 * things here carry weight beyond the CRUD: the URL is checked before it is
 * stored, because storing an unsafe one points the server at the inside of its
 * own network, and the secret is never returned.
 */
final class WebhookControllerTest extends TestCase
{
    private \PDO $pdo;
    private WebhookRepository $repository;

    #[\Override]
    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->repository = new WebhookRepository($this->pdo);
    }

    private function controller(string $mode = 'standalone'): WebhookController
    {
        return new WebhookController($this->repository, new OutboundUrlValidator($mode));
    }

    private static function admin(): WebCalendarUser
    {
        return new WebCalendarUser(new User('admin', 'Ad', 'Min', 'admin@example.com', true, true), null);
    }

    private static function ordinaryUser(): WebCalendarUser
    {
        return new WebCalendarUser(new User('bob', 'Bob', 'Jones', 'bob@example.com', false, true), null);
    }

    private static function request(string $body = ''): Request
    {
        return Request::create('/api/v2/admin/webhooks', 'POST', [], [], [], [], $body);
    }

    /** @return array<string, mixed> */
    private static function dataOf(Response $response): array
    {
        /** @var array{data: array<string, mixed>} $body */
        $body = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $body['data'];
    }

    private function seed(string $url = 'https://hooks.example.com/a', string $events = 'event.created', string $secret = 'seed-secret', bool $enabled = true): int
    {
        return $this->repository->save(new WebhookSubscription(0, $url, $events, $secret, $enabled));
    }

    // ------------------------------------------------------------- the guard

    /** @return iterable<string, array{string}> */
    public static function everyEndpoint(): iterable
    {
        yield 'list' => ['list'];
        yield 'create' => ['create'];
        yield 'update' => ['update'];
        yield 'delete' => ['delete'];
    }

    #[DataProvider('everyEndpoint')]
    public function testAnAnonymousCallerIsRefused(string $endpoint): void
    {
        self::assertSame(403, $this->callAs(null, $endpoint)->getStatusCode());
    }

    #[DataProvider('everyEndpoint')]
    public function testAnOrdinaryUserIsRefused(string $endpoint): void
    {
        // A webhook delivers calendar events to wherever it points, so
        // creating one is an administrator's decision.
        self::assertSame(403, $this->callAs(self::ordinaryUser(), $endpoint)->getStatusCode());
    }

    #[DataProvider('everyEndpoint')]
    public function testNothingIsChangedByARefusedCall(string $endpoint): void
    {
        $id = $this->seed();

        $this->callAs(self::ordinaryUser(), $endpoint);

        self::assertCount(1, $this->repository->findAll(), 'a refused call must not delete anything');
        self::assertSame('https://hooks.example.com/a', $this->repository->findById($id)?->url());
    }

    private function callAs(?WebCalendarUser $user, string $endpoint): Response
    {
        $controller = $this->controller();
        $body = '{"url":"https://hooks.example.com/new"}';

        return match ($endpoint) {
            'list' => $controller->list($user),
            'create' => $controller->create(self::request($body), $user),
            'update' => $controller->update(1, self::request($body), $user),
            'delete' => $controller->delete(1, $user),
        };
    }

    // ------------------------------------------------------------- creating

    public function testCreatingAWebhookReturnsItAndStoresIt(): void
    {
        $response = $this->controller()->create(
            self::request('{"url":"https://hooks.example.com/new","events":"event.created"}'),
            self::admin(),
        );

        self::assertSame(201, $response->getStatusCode());
        $data = self::dataOf($response);
        self::assertSame('https://hooks.example.com/new', $data['url']);
        self::assertSame('event.created', $data['events']);
        self::assertTrue($data['enabled']);

        self::assertCount(1, $this->repository->findAll());
    }

    public function testTheSecretNeverLeavesTheServer(): void
    {
        // A receiver authenticates a delivery by recomputing the HMAC over the
        // body with this secret. Anyone who reads it can forge deliveries, so
        // it must not come back in the reply -- not even to the administrator
        // who set it.
        $response = $this->controller()->create(
            self::request('{"url":"https://hooks.example.com/new","secret":"top-secret-value"}'),
            self::admin(),
        );

        self::assertArrayNotHasKey('secret', self::dataOf($response));
        self::assertStringNotContainsString('top-secret-value', (string) $response->getContent());
    }

    public function testAWebhookWithNoSecretIsGivenARandomOne(): void
    {
        // Without one there is nothing for a receiver to verify, so an
        // omitted secret must not mean an empty secret.
        $this->controller()->create(self::request('{"url":"https://hooks.example.com/new"}'), self::admin());

        $stored = $this->repository->findAll()[0];
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $stored->secret());
    }

    public function testAWebhookWithNoEventsSubscribesToAll(): void
    {
        $this->controller()->create(self::request('{"url":"https://hooks.example.com/new"}'), self::admin());

        self::assertSame('*', $this->repository->findAll()[0]->events());
    }

    /** @return iterable<string, array{string, bool}> */
    public static function enabledInputs(): iterable
    {
        yield 'absent means on' => ['{"url":"https://hooks.example.com/n"}', true];
        yield 'true' => ['{"url":"https://hooks.example.com/n","enabled":true}', true];
        yield 'false' => ['{"url":"https://hooks.example.com/n","enabled":false}', false];
        yield 'zero' => ['{"url":"https://hooks.example.com/n","enabled":0}', false];
    }

    #[DataProvider('enabledInputs')]
    public function testWhetherANewWebhookIsLive(string $body, bool $expected): void
    {
        $this->controller()->create(self::request($body), self::admin());

        self::assertSame($expected, $this->repository->findAll()[0]->isEnabled());
    }

    /** @return iterable<string, array{string}> */
    public static function bodiesWithoutAUsableUrl(): iterable
    {
        yield 'not json' => ['not json at all'];
        yield 'no url' => ['{"events":"event.created"}'];
        yield 'an empty url' => ['{"url":""}'];
        yield 'a url that is not a string' => ['{"url":42}'];
    }

    #[DataProvider('bodiesWithoutAUsableUrl')]
    public function testAWebhookNeedsAUrl(string $body): void
    {
        $response = $this->controller()->create(self::request($body), self::admin());

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([], $this->repository->findAll());
    }

    // ------------------------------------------------- where it may point

    /** @return iterable<string, array{string, string}> */
    public static function unsafeUrls(): iterable
    {
        // Storing one of these would have the server fetch it on every
        // matching event -- the cloud metadata service being the classic
        // target, since it hands out credentials to whoever asks from inside.
        yield 'loopback' => ['http://127.0.0.1/hook', 'hosted'];
        yield 'cloud metadata' => ['http://169.254.169.254/latest/meta-data/', 'hosted'];
        yield 'a private address' => ['http://10.0.0.5/hook', 'hosted'];
        yield 'a scheme that is not http' => ['ftp://example.com/hook', 'standalone'];
        yield 'not a url at all' => ['javascript:alert(1)', 'standalone'];
    }

    #[DataProvider('unsafeUrls')]
    public function testAnUnsafeUrlIsRefusedOnCreate(string $url, string $mode): void
    {
        $response = $this->controller($mode)->create(
            self::request((string) json_encode(['url' => $url])),
            self::admin(),
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([], $this->repository->findAll(), 'nothing may be stored');
    }

    #[DataProvider('unsafeUrls')]
    public function testAnUnsafeUrlIsRefusedOnUpdate(string $url, string $mode): void
    {
        // The same door, on the way in through an edit rather than a create.
        $id = $this->seed();

        $response = $this->controller($mode)->update(
            $id,
            self::request((string) json_encode(['url' => $url])),
            self::admin(),
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('https://hooks.example.com/a', $this->repository->findById($id)?->url());
    }

    // ------------------------------------------------------------- updating

    public function testUpdatingAWebhookThatIsNotThereSaysSo(): void
    {
        $response = $this->controller()->update(999, self::request('{"url":"https://hooks.example.com/x"}'), self::admin());

        self::assertSame(404, $response->getStatusCode());
    }

    public function testAnUpdateChangesOnlyWhatItWasGiven(): void
    {
        // Every field falls back to the stored one, so a caller sending just a
        // url must not have the events, the secret or the enabled flag reset
        // underneath them.
        $id = $this->seed(events: 'event.created,event.deleted', secret: 'the-original-secret', enabled: false);

        $response = $this->controller()->update(
            $id,
            self::request('{"url":"https://hooks.example.com/moved"}'),
            self::admin(),
        );

        self::assertSame(200, $response->getStatusCode());
        $stored = $this->repository->findById($id);
        self::assertNotNull($stored);
        self::assertSame('https://hooks.example.com/moved', $stored->url());
        self::assertSame('event.created,event.deleted', $stored->events());
        self::assertSame('the-original-secret', $stored->secret());
        self::assertFalse($stored->isEnabled());
    }

    public function testAnUpdateCanChangeEveryField(): void
    {
        $id = $this->seed(events: 'event.created', secret: 'old', enabled: true);

        $this->controller()->update(
            $id,
            self::request('{"url":"https://hooks.example.com/two","events":"*","secret":"new","enabled":false}'),
            self::admin(),
        );

        $stored = $this->repository->findById($id);
        self::assertNotNull($stored);
        self::assertSame('https://hooks.example.com/two', $stored->url());
        self::assertSame('*', $stored->events());
        self::assertSame('new', $stored->secret());
        self::assertFalse($stored->isEnabled());
    }

    public function testAnUpdateDoesNotAddASecondWebhook(): void
    {
        $id = $this->seed();

        $this->controller()->update($id, self::request('{"events":"*"}'), self::admin());

        self::assertCount(1, $this->repository->findAll());
    }

    public function testAnUpdateWithABodyThatIsNotJsonIsRefused(): void
    {
        $id = $this->seed(events: 'event.created');

        $response = $this->controller()->update($id, self::request('not json'), self::admin());

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('event.created', $this->repository->findById($id)?->events());
    }

    // ------------------------------------------------------------- the rest

    public function testTheListingReturnsEveryWebhookAndNoSecrets(): void
    {
        $this->seed('https://hooks.example.com/a', secret: 'secret-one');
        $this->seed('https://hooks.example.com/b', secret: 'secret-two');

        $response = $this->controller()->list(self::admin());

        /** @var array{data: list<array<string, mixed>>} $body */
        $body = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertCount(2, $body['data']);
        self::assertStringNotContainsString('secret-one', (string) $response->getContent());
        self::assertStringNotContainsString('secret-two', (string) $response->getContent());

        // Each entry has to be the array form. Handing back the objects
        // themselves encodes every one as {} -- still two entries, still no
        // secret, and nothing a client can display.
        self::assertSame(
            ['https://hooks.example.com/a', 'https://hooks.example.com/b'],
            array_column($body['data'], 'url'),
        );
        self::assertArrayHasKey('id', $body['data'][0]);
        self::assertArrayHasKey('events', $body['data'][0]);
        self::assertArrayHasKey('enabled', $body['data'][0]);
    }

    public function testDeletingAWebhookRemovesThatOneOnly(): void
    {
        $doomed = $this->seed('https://hooks.example.com/doomed');
        $keeper = $this->seed('https://hooks.example.com/keeper');

        $response = $this->controller()->delete($doomed, self::admin());

        self::assertSame(204, $response->getStatusCode());
        self::assertNull($this->repository->findById($doomed));
        self::assertNotNull($this->repository->findById($keeper));
    }

    public function testDeletingOneThatIsNotThereIsStillASuccess(): void
    {
        // Characterisation: delete does not look first, so repeating it is
        // harmless rather than a 404.
        $kept = $this->seed();

        self::assertSame(204, $this->controller()->delete(999, self::admin())->getStatusCode());
        self::assertNotNull($this->repository->findById($kept));
    }
}
