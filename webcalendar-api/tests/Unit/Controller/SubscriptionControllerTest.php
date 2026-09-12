<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Api\SubscriptionController;
use App\Security\OutboundUrlValidator;
use App\Security\WebCalendarUser;
use App\Subscription\SubscriptionRepository;
use App\Tests\Unit\Subscription\RecordingIcsFetcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use WebCalendar\Core\Domain\Entity\User;

/**
 * Subscribed ICS calendars.
 *
 * Nothing executed a line of this controller. The weight here is not the CRUD:
 * a subscription is a URL the server fetches on behalf of whoever stored it,
 * and any authenticated user can store one, so the checks that keep that
 * request from reaching the inside of the network are the point -- at the
 * moment it is stored, and again on every fetch, because rows outlive checks.
 */
final class SubscriptionControllerTest extends TestCase
{
    private \PDO $pdo;
    private SubscriptionRepository $repo;

    #[\Override]
    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->repo = new SubscriptionRepository($this->pdo);
    }

    private function controller(
        ?RecordingIcsFetcher $fetcher = null,
        string $mode = 'hosted',
    ): SubscriptionController {
        return new SubscriptionController(
            $this->repo,
            $fetcher ?? new RecordingIcsFetcher(),
            new OutboundUrlValidator($mode),
        );
    }

    private static function user(string $login = 'alice'): WebCalendarUser
    {
        return new WebCalendarUser(new User($login, 'A', 'B', "{$login}@example.com", false, true), null);
    }

    private static function request(string $body = ''): Request
    {
        return Request::create('/api/v2/calendars/subscribe', 'POST', [], [], [], [], $body);
    }

    /** @return array<string, mixed> */
    private static function payload(Response $response): array
    {
        $body = $response->getContent();
        self::assertIsString($body);
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    // ------------------------------------------------------- authentication

    /**
     * Every route is reachable without a session if the guard is dropped, and
     * three of the four take an id straight from the URL.
     */
    #[DataProvider('anonymousCalls')]
    public function testEveryRouteRefusesAnAnonymousCaller(\Closure $call): void
    {
        $response = $call($this->controller());

        $this->assertSame(401, $response->getStatusCode());
    }

    /** @return iterable<string, array{\Closure}> */
    public static function anonymousCalls(): iterable
    {
        yield 'list' => [static fn(SubscriptionController $c): Response => $c->list(null)];
        yield 'create' => [static fn(SubscriptionController $c): Response => $c->create(self::request('{}'), null)];
        yield 'delete' => [static fn(SubscriptionController $c): Response => $c->delete(1, null)];
        yield 'events' => [static fn(SubscriptionController $c): Response => $c->events(1, null)];
    }

    // ---------------------------------------------------------------- list

    public function testListReturnsOnlyTheCallersSubscriptions(): void
    {
        $this->repo->create('alice', 'https://example.com/a.ics', 'Alice', '#aaaaaa');
        $this->repo->create('bob', 'https://example.com/b.ics', 'Bob', '#bbbbbb');

        $payload = self::payload($this->controller()->list(self::user('alice')));

        $this->assertIsArray($payload['data']);
        $this->assertCount(1, $payload['data']);
        $this->assertSame('https://example.com/a.ics', $payload['data'][0]['url']);
    }

    public function testListDescribesEachSubscriptionInFull(): void
    {
        // The client keys the per-calendar events request off `id` and shows
        // the rest; a field missing from the serialisation takes the whole
        // calendar out of the UI, and nothing else reads these back.
        $sub = $this->repo->create('alice', 'https://example.com/a.ics', 'Alice', '#aaaaaa', 1800);
        $this->repo->updateFetchStatus($sub->id(), '"v1"');

        $payload = self::payload($this->controller()->list(self::user('alice')));

        $row = $payload['data'][0];
        $this->assertSame(
            ['id', 'user_login', 'url', 'name', 'color', 'refresh_interval', 'last_fetched', 'etag'],
            array_keys($row),
        );
        $this->assertSame($sub->id(), $row['id']);
        $this->assertSame('alice', $row['user_login']);
        $this->assertSame('Alice', $row['name']);
        $this->assertSame('#aaaaaa', $row['color']);
        $this->assertSame(1800, $row['refresh_interval']);
        $this->assertSame('"v1"', $row['etag']);
        $this->assertNotNull($row['last_fetched']);
    }

    // -------------------------------------------------------------- create

    public function testCreateStoresTheSubscriptionForTheCaller(): void
    {
        $response = $this->controller()->create(
            self::request('{"url":"https://example.com/cal.ics","name":"Holidays","color":"#123456","refresh_interval":900}'),
            self::user('alice'),
        );

        $this->assertSame(201, $response->getStatusCode());

        $stored = $this->repo->findByUser('alice');
        $this->assertCount(1, $stored);
        $this->assertSame('https://example.com/cal.ics', $stored[0]->url());
        $this->assertSame('Holidays', $stored[0]->name());
        $this->assertSame('#123456', $stored[0]->color());
        $this->assertSame(900, $stored[0]->refreshInterval());
    }

    public function testCreateDefaultsTheColourAndInterval(): void
    {
        $this->controller()->create(
            self::request('{"url":"https://example.com/cal.ics","name":"Holidays"}'),
            self::user('alice'),
        );

        $stored = $this->repo->findByUser('alice');
        $this->assertSame('#3788d8', $stored[0]->color());
        $this->assertSame(3600, $stored[0]->refreshInterval());
    }

    /**
     * Left open, one row is a standing instruction to fetch a chosen URL as
     * often as the refresh job runs.
     */
    #[DataProvider('refreshIntervals')]
    public function testCreateRefusesARefreshIntervalBelowTheFloor(int $interval, int $expected): void
    {
        $response = $this->controller()->create(
            self::request(json_encode(
                ['url' => 'https://example.com/cal.ics', 'name' => 'Holidays', 'refresh_interval' => $interval],
                \JSON_THROW_ON_ERROR,
            )),
            self::user('alice'),
        );

        $this->assertSame($expected, $response->getStatusCode());
        $this->assertCount($expected === 201 ? 1 : 0, $this->repo->findByUser('alice'));
    }

    /** @return iterable<string, array{int, int}> */
    public static function refreshIntervals(): iterable
    {
        yield 'every tick' => [0, 400];
        yield 'negative' => [-1, 400];
        yield 'one second' => [1, 400];
        yield 'just under the floor' => [299, 400];
        yield 'at the floor' => [300, 201];
        yield 'hourly' => [3600, 201];
    }

    #[DataProvider('incompleteBodies')]
    public function testCreateRefusesABodyWithoutBothFields(string $body): void
    {
        $response = $this->controller()->create(self::request($body), self::user());

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $this->repo->findByUser('alice'));
    }

    /** @return iterable<string, array{string}> */
    public static function incompleteBodies(): iterable
    {
        yield 'no url' => ['{"name":"Holidays"}'];
        yield 'no name' => ['{"url":"https://example.com/cal.ics"}'];
        yield 'empty url' => ['{"url":"","name":"Holidays"}'];
        yield 'empty name' => ['{"url":"https://example.com/cal.ics","name":""}'];
        yield 'not an object' => ['"just a string"'];
        yield 'not json at all' => ['<html>'];
        yield 'empty body' => [''];
    }

    /**
     * A JSON body is whatever the client sent, not the shape the docblock
     * claims: every one of these used to reach a typed parameter and take the
     * request down with a TypeError.
     */
    #[DataProvider('wronglyTypedBodies')]
    public function testCreateSurvivesFieldsOfTheWrongType(string $body, int $expected): void
    {
        $response = $this->controller()->create(self::request($body), self::user());

        $this->assertSame($expected, $response->getStatusCode());
    }

    /** @return iterable<string, array{string, int}> */
    public static function wronglyTypedBodies(): iterable
    {
        yield 'numeric url' => ['{"url":12345,"name":"Holidays"}', 400];
        yield 'array name' => ['{"url":"https://example.com/cal.ics","name":["Holidays"]}', 400];
        yield 'string interval' => ['{"url":"https://example.com/cal.ics","name":"H","refresh_interval":"900"}', 201];
        yield 'numeric colour' => ['{"url":"https://example.com/cal.ics","name":"H","color":16711680}', 201];
    }

    // ------------------------------------------------------- outbound checks

    /**
     * The URL is fetched by the server, so it carries the same risk a webhook
     * target does -- and unlike a webhook, any authenticated user can store
     * one.
     */
    #[DataProvider('unsafeUrls')]
    public function testCreateRefusesAUrlTheServerMustNotFetch(string $url): void
    {
        $response = $this->controller()->create(
            self::request(json_encode(['url' => $url, 'name' => 'Holidays'], \JSON_THROW_ON_ERROR)),
            self::user('alice'),
        );

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $this->repo->findByUser('alice'));
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeUrls(): iterable
    {
        yield 'local file' => ['file:///etc/passwd'];
        yield 'php wrapper' => ['php://filter/read=convert.base64-encode/resource=/etc/passwd'];
        yield 'data wrapper' => ['data://text/plain;base64,QkVHSU46VkNBTEVOREFS'];
        yield 'ftp' => ['ftp://example.com/cal.ics'];
        yield 'loopback' => ['http://127.0.0.1/cal.ics'];
        yield 'loopback by name' => ['http://localhost/cal.ics'];
        yield 'ipv6 loopback' => ['http://[::1]/cal.ics'];
        yield 'private range' => ['http://10.1.2.3/cal.ics'];
        yield 'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'embedded credentials' => ['https://user:pass@example.com/cal.ics'];
        yield 'relative' => ['/etc/passwd'];
    }

    /**
     * Standalone deployments may point a subscription at an internal host, so
     * the network half is off there -- but no deployment has a reason to let
     * one name a local file.
     */
    public function testStandaloneStillRefusesANonHttpUrl(): void
    {
        $response = $this->controller(mode: 'standalone')->create(
            self::request('{"url":"file:///etc/passwd","name":"Holidays"}'),
            self::user('alice'),
        );

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $this->repo->findByUser('alice'));
    }

    public function testStandaloneAllowsAnInternalHost(): void
    {
        $response = $this->controller(mode: 'standalone')->create(
            self::request('{"url":"http://10.1.2.3/cal.ics","name":"Intranet"}'),
            self::user('alice'),
        );

        $this->assertSame(201, $response->getStatusCode());
        $this->assertCount(1, $this->repo->findByUser('alice'));
    }

    /**
     * The fetch is checked again where it happens, so a row stored before any
     * of this existed still cannot be fetched.
     */
    public function testEventsRefusesAStoredUrlThatFailsTheChecks(): void
    {
        $sub = $this->repo->create('alice', 'file:///etc/passwd', 'Legacy', '#000000');
        $fetcher = new RecordingIcsFetcher(rejection: new \InvalidArgumentException('URL scheme "file" is not allowed; use http or https.'));

        $response = $this->controller($fetcher)->events($sub->id(), self::user('alice'));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('URL scheme "file" is not allowed; use http or https.', self::payload($response)['error']['message']);
    }

    // -------------------------------------------------------------- delete

    public function testDeleteRemovesTheCallersSubscription(): void
    {
        $sub = $this->repo->create('alice', 'https://example.com/a.ics', 'Alice', '#aaaaaa');

        $response = $this->controller()->delete($sub->id(), self::user('alice'));

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame([], $this->repo->findByUser('alice'));
    }

    public function testDeleteLeavesAnotherUsersSubscriptionAlone(): void
    {
        $sub = $this->repo->create('bob', 'https://example.com/b.ics', 'Bob', '#bbbbbb');

        $response = $this->controller()->delete($sub->id(), self::user('alice'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertCount(1, $this->repo->findByUser('bob'));
    }

    public function testDeleteReportsAnUnknownIdAsMissing(): void
    {
        $this->assertSame(404, $this->controller()->delete(4242, self::user('alice'))->getStatusCode());
    }

    // -------------------------------------------------------------- events

    public function testEventsRefusesAnotherUsersSubscription(): void
    {
        $sub = $this->repo->create('bob', 'https://example.com/b.ics', 'Bob', '#bbbbbb');
        $fetcher = new RecordingIcsFetcher(['body' => self::ics(), 'etag' => null]);

        $response = $this->controller($fetcher)->events($sub->id(), self::user('alice'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame([], $fetcher->calls, 'the feed must not be fetched for a subscription the caller does not own');
    }

    public function testEventsReportsAnUnknownIdAsMissing(): void
    {
        $this->assertSame(404, $this->controller()->events(4242, self::user('alice'))->getStatusCode());
    }

    public function testEventsSendsTheStoredEtagAsAConditionalGet(): void
    {
        $sub = $this->repo->create('alice', 'https://example.com/a.ics', 'Alice', '#aaaaaa');
        $this->repo->updateFetchStatus($sub->id(), '"v1"');
        $fetcher = new RecordingIcsFetcher(['body' => self::ics(), 'etag' => '"v2"']);

        $this->controller($fetcher)->events($sub->id(), self::user('alice'));

        $this->assertSame([['url' => 'https://example.com/a.ics', 'etag' => '"v1"']], $fetcher->calls);
    }

    public function testEventsStoresTheEtagTheFeedCameBackWith(): void
    {
        $sub = $this->repo->create('alice', 'https://example.com/a.ics', 'Alice', '#aaaaaa');
        $fetcher = new RecordingIcsFetcher(['body' => self::ics(), 'etag' => '"v2"']);

        $this->controller($fetcher)->events($sub->id(), self::user('alice'));

        $stored = $this->repo->findById($sub->id());
        $this->assertNotNull($stored);
        $this->assertSame('"v2"', $stored->etag());
        $this->assertNotNull($stored->lastFetched());
    }

    public function testEventsReportsAnUnchangedFeedAsCached(): void
    {
        $sub = $this->repo->create('alice', 'https://example.com/a.ics', 'Alice', '#aaaaaa');
        $this->repo->updateFetchStatus($sub->id(), '"v1"');
        $before = $this->repo->findById($sub->id());
        self::assertNotNull($before);

        $payload = self::payload($this->controller(new RecordingIcsFetcher())->events($sub->id(), self::user('alice')));

        $this->assertTrue($payload['data']['cached']);
        $this->assertSame([], $payload['data']['events']);
        $this->assertSame($sub->id(), $payload['data']['subscription_id']);

        $after = $this->repo->findById($sub->id());
        $this->assertNotNull($after);
        $this->assertSame('"v1"', $after->etag(), 'a 304 must not overwrite the stored validator');
    }

    public function testEventsParsesTheFeedItFetched(): void
    {
        $sub = $this->repo->create('alice', 'https://example.com/a.ics', 'Alice Cal', '#abcdef');
        $fetcher = new RecordingIcsFetcher(['body' => self::ics(), 'etag' => null]);

        $payload = self::payload($this->controller($fetcher)->events($sub->id(), self::user('alice')));

        $this->assertFalse($payload['data']['cached']);
        $this->assertSame($sub->id(), $payload['data']['subscription_id']);
        $events = $payload['data']['events'];
        $this->assertCount(2, $events);

        $this->assertSame('Team standup', $events[0]['title']);
        $this->assertSame('20260401T090000Z', $events[0]['start']);
        $this->assertSame('20260401T093000Z', $events[0]['end']);
        $this->assertFalse($events[0]['all_day']);
        $this->assertSame('Daily sync', $events[0]['description']);
        $this->assertSame('Room 2', $events[0]['location']);
        $this->assertSame('Alice Cal', $events[0]['source']);
        $this->assertSame('#abcdef', $events[0]['color']);
        $this->assertTrue($events[0]['read_only']);

        $this->assertSame('Company holiday', $events[1]['title']);
        $this->assertSame('20260704', $events[1]['start']);
        $this->assertSame('20260705', $events[1]['end']);
        $this->assertTrue($events[1]['all_day']);
    }

    /**
     * The parser keys on SUMMARY, so a body that is not a calendar at all --
     * which is what an SSRF probe gets back -- yields nothing rather than
     * appearing as an event.
     */
    #[DataProvider('feedsWithNothingToShow')]
    public function testAFeedWithNoNamedEventYieldsNone(string $body): void
    {
        $sub = $this->repo->create('alice', 'https://example.com/a.ics', 'Alice', '#aaaaaa');
        $fetcher = new RecordingIcsFetcher(['body' => $body, 'etag' => null]);

        $payload = self::payload($this->controller($fetcher)->events($sub->id(), self::user('alice')));

        $this->assertSame([], $payload['data']['events']);
    }

    /** @return iterable<string, array{string}> */
    public static function feedsWithNothingToShow(): iterable
    {
        yield 'empty' => [''];
        yield 'not a calendar' => ["root:x:0:0:root:/root:/bin/bash\ndaemon:x:1:1:daemon:/usr/sbin:/usr/sbin/nologin\n"];
        yield 'calendar with no events' => ["BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n"];
        yield 'event with no summary' => ["BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nDTSTART:20260401T090000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"];
    }

    private static function ics(): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"
            . "BEGIN:VEVENT\r\nUID:1\r\nSUMMARY:Team standup\r\nDESCRIPTION:Daily sync\r\nLOCATION:Room 2\r\n"
            . "DTSTART:20260401T090000Z\r\nDTEND:20260401T093000Z\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:2\r\nSUMMARY:Company holiday\r\n"
            . "DTSTART;VALUE=DATE:20260704\r\nDTEND;VALUE=DATE:20260705\r\nEND:VEVENT\r\n"
            . "END:VCALENDAR\r\n";
    }
}
