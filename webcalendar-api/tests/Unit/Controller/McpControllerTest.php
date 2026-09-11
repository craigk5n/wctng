<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Api\McpController;
use App\Service\CoreServiceFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\UserPreference;

final class McpControllerTest extends TestCase
{
    private \PDO $pdo;
    private CoreServiceFactory $factory;
    private McpController $controller;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Load schema from vendor (installed via Composer from GitHub)
        $schemaPath = realpath(__DIR__ . '/../../../vendor/craigk5n/webcalendar-core/src/Infrastructure/Persistence/sqlite-schema.sql');
        if ($schemaPath !== false) {
            $schema = file_get_contents($schemaPath);
            if ($schema !== false) {
                $clean = (string) preg_replace('/--[^\n]*/', '', $schema);
                /** @var string[] $stmts */
                $stmts = preg_split('/;\s*\n/', $clean) ?? [];
                foreach ($stmts as $s) {
                    $s = trim($s);
                    if ($s !== '') {
                        try {
                            $this->pdo->exec($s);
                        } catch (\PDOException) {
                        }
                    }
                }
            }
        }

        $this->factory = new CoreServiceFactory($this->pdo, 'test_secret');

        // Create admin user with API token
        $admin = new User('admin', 'Admin', 'User', 'admin@test.com', true, true);
        $this->factory->getUserService()->createUser($admin, $admin);
        $this->factory->getUserRepository()->savePreference('admin', new UserPreference('api_token', 'test-token-123'));

        $this->controller = new McpController(
            $this->factory->getEventService(),
            $this->factory->getUserService(),
            $this->factory->getBookingService(),
            $this->factory->getEventRepository(),
            $this->factory->getUserRepository(),
        );
    }

    public function testToolsListReturnsToolDefinitions(): void
    {
        $request = $this->makeRequest('tools/list', [], 'test-token-123');
        $response = $this->controller->handle($request);

        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame('2.0', $body['jsonrpc']);
        $this->assertArrayHasKey('result', $body);
        $this->assertArrayHasKey('tools', $body['result']);
        $this->assertGreaterThan(5, \count($body['result']['tools']));

        $toolNames = array_column($body['result']['tools'], 'name');
        $this->assertContains('list_events', $toolNames);
        $this->assertContains('create_event', $toolNames);
        $this->assertContains('search_events', $toolNames);
    }

    public function testAuthenticationRequired(): void
    {
        $request = $this->makeRequest('tools/list', []);
        $response = $this->controller->handle($request);

        $body = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('error', $body);
        $this->assertSame(-32000, $body['error']['code']);
    }

    public function testCreateAndGetEvent(): void
    {
        $request = $this->makeRequest('tools/call', [
            'name' => 'create_event',
            'arguments' => [
                'title' => 'MCP Test Event',
                'start_date' => '20260601',
                'start_time' => '100000',
                'duration' => 60,
                'description' => 'Created via MCP',
            ],
        ], 'test-token-123');

        $response = $this->controller->handle($request);
        $body = json_decode((string) $response->getContent(), true);

        $this->assertTrue($body['result']['created']);
        $this->assertSame('MCP Test Event', $body['result']['event']['title']);
    }

    public function testUnknownMethodReturnsError(): void
    {
        $request = $this->makeRequest('nonexistent', [], 'test-token-123');
        $response = $this->controller->handle($request);

        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame(-32601, $body['error']['code']);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function makeRequest(string $method, array $params, string $token = ''): Request
    {
        $body = json_encode([
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
            'id' => 1,
        ], \JSON_THROW_ON_ERROR);

        $headers = ['CONTENT_TYPE' => 'application/json'];
        if ($token !== '') {
            $headers['HTTP_X_API_TOKEN'] = $token;
        }

        return Request::create('/api/v2/mcp', 'POST', [], [], [], $headers, $body);
    }

    // ------------------------------------------------------------------ auth

    /** @return array<string, mixed> */
    private function call(string $method, array $params = [], string $token = 'test-token-123'): array
    {
        $response = $this->controller->handle($this->makeRequest($method, $params, $token));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $body;
    }

    public function testAWrongTokenIsRejectedEvenThoughTheUserHasOne(): void
    {
        // The check is `key === 'api_token' && hash_equals(value, token)`. An
        // `||` there authenticates anyone whose preferences merely contain an
        // api_token, whatever they sent -- every user in the table, with any
        // string at all. Nothing covered it.
        $body = $this->call('tools/list', [], 'not-the-right-token');

        self::assertArrayHasKey('error', $body);
        self::assertSame(-32000, $body['error']['code']);
    }

    public function testATokenBelongingToNoPreferenceKeyIsRejected(): void
    {
        // The other half of the same `&&`: a preference that holds the token
        // as its value but is not the api_token key must not authenticate.
        $this->factory->getUserRepository()->savePreference(
            'admin',
            new UserPreference('some_other_setting', 'sneaky-token'),
        );

        $body = $this->call('tools/list', [], 'sneaky-token');

        self::assertArrayHasKey('error', $body);
        self::assertSame(-32000, $body['error']['code']);
    }

    public function testTheApiTokenHeaderIsPreferredOverAuthorization(): void
    {
        // `X-API-Token ?? Authorization`: swapping the operands makes the
        // fallback win, so a stale Authorization header would beat the token
        // the caller actually meant to use.
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'tools/list', 'params' => [], 'id' => 1], \JSON_THROW_ON_ERROR);
        $request = Request::create('/api/v2/mcp', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_API_TOKEN' => 'test-token-123',
            'HTTP_AUTHORIZATION' => 'Bearer wrong-token',
        ], $body);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $this->controller->handle($request)->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('result', $decoded);
    }

    public function testABearerTokenInAuthorizationIsAccepted(): void
    {
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'tools/list', 'params' => [], 'id' => 1], \JSON_THROW_ON_ERROR);
        $request = Request::create('/api/v2/mcp', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer test-token-123',
        ], $body);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $this->controller->handle($request)->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('result', $decoded);
    }

    // ------------------------------------------------------------- dispatch

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function tools(): iterable
    {
        yield 'list_events' => ['list_events', ['start_date' => '20260601', 'end_date' => '20260630']];
        yield 'get_event' => ['get_event', ['id' => 999999]];
        yield 'create_event' => ['create_event', ['title' => 'T', 'start_date' => '20260601']];
        yield 'update_event' => ['update_event', ['id' => 999999, 'title' => 'T']];
        yield 'delete_event' => ['delete_event', ['id' => 999999]];
        yield 'search_events' => ['search_events', ['query' => 'anything']];
        yield 'get_availability' => ['get_availability', ['date' => '20260601']];
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('tools')]
    public function testEveryToolNameReachesItsOwnHandler(string $tool, array $arguments): void
    {
        // Each name is a match arm, and a removed arm falls through to
        // "Unknown tool" -- so the test only has to show the call was routed
        // somewhere, not that it succeeded.
        $body = $this->call('tools/call', ['name' => $tool, 'arguments' => $arguments]);

        $message = isset($body['error']) ? (string) $body['error']['message'] : '';
        self::assertStringNotContainsString('Unknown tool', $message, $tool . ' fell through to the default arm');
    }

    public function testAnUnknownToolSaysSoWithTheMethodNotFoundCode(): void
    {
        $body = $this->call('tools/call', ['name' => 'no_such_tool', 'arguments' => []]);

        // -32601 is JSON-RPC's "method not found"; a client keys its handling
        // off the number, not the sentence.
        self::assertSame(-32601, $body['error']['code']);
        self::assertStringContainsString('no_such_tool', (string) $body['error']['message']);
    }

    public function testTheEnvelopeCarriesTheProtocolVersionAndTheRequestId(): void
    {
        $body = $this->call('tools/list');

        self::assertSame(['jsonrpc', 'id', 'result'], array_keys($body));
        self::assertSame('2.0', $body['jsonrpc']);
        self::assertSame(1, $body['id']);
    }

    public function testAnErrorEnvelopeCarriesThemToo(): void
    {
        $body = $this->call('nonexistent');

        self::assertSame(['jsonrpc', 'id', 'error'], array_keys($body));
        self::assertSame('2.0', $body['jsonrpc']);
        self::assertSame(1, $body['id']);
    }

    // --------------------------------------------------------- create_event

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function incompleteEvents(): iterable
    {
        yield 'neither' => [[]];
        yield 'no title' => [['start_date' => '20260601']];
        yield 'no start date' => [['title' => 'Some Event']];
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('incompleteEvents')]
    public function testBothTitleAndStartDateAreRequired(array $arguments): void
    {
        // One at a time: with both missing, an `&&` in place of the `||` still
        // rejects, so only the half-supplied cases can tell them apart.
        $body = $this->call('tools/call', ['name' => 'create_event', 'arguments' => $arguments]);

        self::assertSame(-32602, $body['error']['code']);
        self::assertStringContainsString('required', (string) $body['error']['message']);
    }

    public function testAnEventWithNoStartTimeIsAllDay(): void
    {
        $body = $this->call('tools/call', ['name' => 'create_event', 'arguments' => [
            'title' => 'All Day Thing',
            'start_date' => '20260601',
        ]]);

        self::assertTrue($body['result']['created']);
        $event = $this->factory->getEventRepository()->findById(new EventId((int) $body['result']['event']['id']));
        self::assertNotNull($event);
        self::assertTrue($event->isAllDay());
        self::assertSame('20260601 00:00:00', $event->start()->format('Ymd H:i:s'));
    }

    public function testAnEventWithAStartTimeIsNotAllDay(): void
    {
        $body = $this->call('tools/call', ['name' => 'create_event', 'arguments' => [
            'title' => 'Timed Thing',
            'start_date' => '20260601',
            'start_time' => '143000',
        ]]);

        $event = $this->factory->getEventRepository()->findById(new EventId((int) $body['result']['event']['id']));
        self::assertNotNull($event);
        self::assertFalse($event->isAllDay());
        self::assertSame('20260601 14:30:00', $event->start()->format('Ymd H:i:s'));
    }

    /** @return iterable<string, array{mixed, int}> */
    public static function durations(): iterable
    {
        yield 'given as a number' => [90, 90];
        yield 'given as a numeric string' => ['45', 45];
        yield 'absent' => [null, 60];
        yield 'not a number at all' => ['soon', 60];
    }

    #[DataProvider('durations')]
    public function testTheDurationFallsBackToAnHour(mixed $given, int $expected): void
    {
        $arguments = ['title' => 'Duration Test', 'start_date' => '20260601', 'start_time' => '090000'];
        if ($given !== null) {
            $arguments['duration'] = $given;
        }

        $body = $this->call('tools/call', ['name' => 'create_event', 'arguments' => $arguments]);

        $event = $this->factory->getEventRepository()->findById(new EventId((int) $body['result']['event']['id']));
        self::assertNotNull($event);
        self::assertSame($expected, $event->duration());
    }

    public function testAnUnparseableDateIsRejected(): void
    {
        $body = $this->call('tools/call', ['name' => 'create_event', 'arguments' => [
            'title' => 'Bad Date',
            'start_date' => 'tomorrow',
        ]]);

        self::assertSame(-32602, $body['error']['code']);
        self::assertStringContainsString('Invalid date', (string) $body['error']['message']);
    }

    public function testEachEventGetsItsOwnUid(): void
    {
        $ids = [];

        foreach (['One', 'Two'] as $title) {
            $body = $this->call('tools/call', ['name' => 'create_event', 'arguments' => [
                'title' => $title,
                'start_date' => '20260601',
            ]]);
            $event = $this->factory->getEventRepository()->findById(new EventId((int) $body['result']['event']['id']));
            self::assertNotNull($event);
            $ids[] = $event->uid();
        }

        self::assertMatchesRegularExpression('/^mcp-[0-9a-f]{16}@webcalendar$/', $ids[0]);
        self::assertNotSame($ids[0], $ids[1], 'two events created in one second still differ');
    }

    // --------------------------------------------------------- delete_event

    public function testDeletingAnEventThatIsNotThereIsAnErrorNotAnException(): void
    {
        // The service throws EventNotFoundException. updateEvent() checks
        // first and answers -32602; deleteEvent() did not, so deleting the
        // same event twice -- which a retrying client does routinely -- left
        // the controller with an uncaught exception instead of a reply.
        $body = $this->call('tools/call', ['name' => 'delete_event', 'arguments' => ['id' => 999999]]);

        self::assertSame(-32602, $body['error']['code']);
        self::assertSame('Event not found', $body['error']['message']);
    }

    public function testDeletingAnEventThatIsThereRemovesIt(): void
    {
        $created = $this->call('tools/call', ['name' => 'create_event', 'arguments' => [
            'title' => 'Doomed', 'start_date' => '20260601',
        ]]);
        $eventId = (int) $created['result']['event']['id'];

        $body = $this->call('tools/call', ['name' => 'delete_event', 'arguments' => ['id' => $eventId]]);

        self::assertTrue($body['result']['deleted']);
        self::assertNull($this->factory->getEventRepository()->findById(new EventId($eventId)));
    }


    // ----------------------------------------------------- the handlers work

    /** Creates an event through the controller and returns its id. */
    private function createEvent(string $title, string $date, string $time = '', int $duration = 60): int
    {
        $arguments = ['title' => $title, 'start_date' => $date, 'duration' => $duration];
        if ($time !== '') {
            $arguments['start_time'] = $time;
        }

        $body = $this->call('tools/call', ['name' => 'create_event', 'arguments' => $arguments]);

        return (int) $body['result']['event']['id'];
    }

    public function testListEventsReturnsOnlyWhatFallsInTheWindow(): void
    {
        $inside = $this->createEvent('Inside', '20260615', '100000');
        $this->createEvent('Before', '20260501', '100000');
        $this->createEvent('After', '20260715', '100000');

        $body = $this->call('tools/call', ['name' => 'list_events', 'arguments' => [
            'start_date' => '20260601',
            'end_date' => '20260630',
        ]]);

        self::assertSame([$inside], array_column($body['result']['events'], 'id'));
    }

    public function testTheWindowIncludesBothItsEndDaysInFull(): void
    {
        // start is snapped to 00:00 and end to 23:59:59, so an event late on
        // the last day is inside the range rather than just after it.
        $first = $this->createEvent('First moment', '20260601', '000000');
        $last = $this->createEvent('Last moment', '20260630', '235900');

        $body = $this->call('tools/call', ['name' => 'list_events', 'arguments' => [
            'start_date' => '20260601',
            'end_date' => '20260630',
        ]]);

        $ids = array_column($body['result']['events'], 'id');
        self::assertContains($first, $ids);
        self::assertContains($last, $ids);
    }

    public function testTheWindowCoversExactlyTheDaysNamed(): void
    {
        // The bounds are snapped with setTime(), and the repository reduces
        // them to Ymd -- so the hour and minute only matter when a mutation
        // rolls them into the neighbouring day. Which is precisely what
        // setTime(24, ...) or a negative minute would do.
        $this->createEvent('Day before', '20260531', '235900');
        $inside = $this->createEvent('Inside', '20260615', '120000');
        $this->createEvent('Day after', '20260701', '000100');

        $body = $this->call('tools/call', ['name' => 'list_events', 'arguments' => [
            'start_date' => '20260601',
            'end_date' => '20260630',
        ]]);

        self::assertSame([$inside], array_column($body['result']['events'], 'id'));
    }

    public function testAnUpdateAdvancesTheSequence(): void
    {
        // iCalendar consumers use SEQUENCE to tell a newer version of an event
        // from the copy they already hold; if it does not move, an updated
        // invitation is ignored.
        $id = $this->createEvent('Versioned', '20260601', '090000');
        $before = $this->factory->getEventRepository()->findById(new EventId($id));
        self::assertNotNull($before);

        $this->call('tools/call', ['name' => 'update_event', 'arguments' => ['id' => $id, 'title' => 'Changed']]);

        $after = $this->factory->getEventRepository()->findById(new EventId($id));
        self::assertNotNull($after);
        self::assertSame($before->sequence() + 1, $after->sequence());
    }

    /** @return iterable<string, array{string}> */
    public static function idBearingTools(): iterable
    {
        yield 'get_event' => ['get_event'];
        yield 'update_event' => ['update_event'];
        yield 'delete_event' => ['delete_event'];
    }

    #[DataProvider('idBearingTools')]
    public function testAnIdSentAsAStringIsAccepted(string $tool): void
    {
        // JSON-RPC clients are not all careful about number versus string,
        // and every handler guards with is_numeric then casts. Without the
        // cast the id is a string where an int is required.
        $id = $this->createEvent('String Id', '20260601', '090000');

        $body = $this->call('tools/call', ['name' => $tool, 'arguments' => ['id' => (string) $id]]);

        self::assertArrayNotHasKey('error', $body, $tool . ' rejected a numeric string id');
    }

    public function testSearchReturnsAtMostTwentyEvents(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->createEvent(sprintf('Zebra %02d', $i), '20260601', sprintf('%02d0000', $i % 24));
        }

        $body = $this->call('tools/call', ['name' => 'search_events', 'arguments' => ['query' => 'Zebra']]);

        self::assertCount(20, $body['result']['events']);
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function badListRanges(): iterable
    {
        yield 'start unparseable' => [['start_date' => 'soon', 'end_date' => '20260630']];
        yield 'end unparseable' => [['start_date' => '20260601', 'end_date' => 'later']];
        yield 'both missing' => [[]];
    }

    /** @param array<string, string> $arguments */
    #[DataProvider('badListRanges')]
    public function testListEventsRejectsADateItCannotRead(array $arguments): void
    {
        $body = $this->call('tools/call', ['name' => 'list_events', 'arguments' => $arguments]);

        self::assertSame(-32602, $body['error']['code']);
        self::assertStringContainsString('YYYYMMDD', (string) $body['error']['message']);
    }

    public function testGetEventReturnsTheEventItWasAskedFor(): void
    {
        $id = $this->createEvent('Fetch Me', '20260601', '090000');

        $body = $this->call('tools/call', ['name' => 'get_event', 'arguments' => ['id' => $id]]);

        self::assertSame($id, $body['result']['event']['id']);
        self::assertSame('Fetch Me', $body['result']['event']['title']);
    }

    public function testGetEventSaysSoWhenThereIsNothingToGet(): void
    {
        $body = $this->call('tools/call', ['name' => 'get_event', 'arguments' => ['id' => 999999]]);

        self::assertSame(-32602, $body['error']['code']);
        self::assertSame('Event not found', $body['error']['message']);
    }

    public function testUpdateEventChangesOnlyWhatWasGiven(): void
    {
        // Every field defaults to the existing value, so a partial update must
        // not blank the rest.
        $id = $this->createEvent('Original Title', '20260601', '090000', 45);

        $body = $this->call('tools/call', ['name' => 'update_event', 'arguments' => [
            'id' => $id,
            'title' => 'New Title',
        ]]);

        self::assertTrue($body['result']['updated']);

        $event = $this->factory->getEventRepository()->findById(new EventId($id));
        self::assertNotNull($event);
        self::assertSame('New Title', $event->name());
        self::assertSame(45, $event->duration(), 'the duration it already had');
        self::assertSame('20260601 09:00:00', $event->start()->format('Ymd H:i:s'));
    }

    public function testUpdateEventCanMoveTheStart(): void
    {
        $id = $this->createEvent('Movable', '20260601', '090000');

        $this->call('tools/call', ['name' => 'update_event', 'arguments' => [
            'id' => $id,
            'start_date' => '20260602',
            'start_time' => '160000',
        ]]);

        $event = $this->factory->getEventRepository()->findById(new EventId($id));
        self::assertNotNull($event);
        self::assertSame('20260602 16:00:00', $event->start()->format('Ymd H:i:s'));
    }

    public function testUpdateEventSaysSoWhenThereIsNothingToUpdate(): void
    {
        $body = $this->call('tools/call', ['name' => 'update_event', 'arguments' => ['id' => 999999, 'title' => 'X']]);

        self::assertSame(-32602, $body['error']['code']);
        self::assertSame('Event not found', $body['error']['message']);
    }

    public function testSearchEventsFindsByTitle(): void
    {
        $zebra = $this->createEvent('Zebra Planning', '20260601', '090000');
        $this->createEvent('Something Else', '20260601', '100000');

        $body = $this->call('tools/call', ['name' => 'search_events', 'arguments' => ['query' => 'Zebra']]);

        self::assertSame([$zebra], array_column($body['result']['events'], 'id'));
    }

    public function testSearchEventsNeedsSomethingToSearchFor(): void
    {
        $body = $this->call('tools/call', ['name' => 'search_events', 'arguments' => ['query' => '']]);

        self::assertSame(-32602, $body['error']['code']);
        self::assertStringContainsString('query', (string) $body['error']['message']);
    }

    public function testAvailabilityReportsSlotsForTheRequestedDay(): void
    {
        $body = $this->call('tools/call', ['name' => 'get_availability', 'arguments' => ['date' => '20260601']]);

        self::assertSame('20260601', $body['result']['date']);
        self::assertSame('admin', $body['result']['user']);
        self::assertIsArray($body['result']['available_slots']);

        foreach ($body['result']['available_slots'] as $slot) {
            self::assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $slot['start']);
            self::assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $slot['end']);
        }
    }

    public function testAvailabilityFallsBackToTheCallerWhenTheUserIsUnknown(): void
    {
        $body = $this->call('tools/call', ['name' => 'get_availability', 'arguments' => [
            'date' => '20260601',
            'user' => 'nobody_at_all',
        ]]);

        // The requested login is echoed back even though the slots belong to
        // the caller, which is worth knowing rather than guessing at.
        self::assertSame('nobody_at_all', $body['result']['user']);
        self::assertIsArray($body['result']['available_slots']);
    }

    public function testAvailabilityRejectsADateItCannotRead(): void
    {
        $body = $this->call('tools/call', ['name' => 'get_availability', 'arguments' => ['date' => 'today']]);

        self::assertSame(-32602, $body['error']['code']);
        self::assertStringContainsString('YYYYMMDD', (string) $body['error']['message']);
    }

    // ----------------------------------------------------------- tools/list

    public function testEachToolDescribesItsParametersAsAJsonSchema(): void
    {
        $tools = $this->call('tools/list')['result']['tools'];

        $byName = array_column($tools, null, 'name');
        self::assertArrayHasKey('create_event', $byName);

        $schema = $byName['create_event']['inputSchema'];
        self::assertSame('object', $schema['type']);
        self::assertArrayHasKey('title', $schema['properties']);
        self::assertSame('string', $schema['properties']['title']['type']);
        self::assertNotSame('', $schema['properties']['title']['description']);
        self::assertContains('title', $schema['required'], 'a required parameter is listed as required');
        self::assertNotContains('location', $schema['required'], 'an optional one is not');
    }

    public function testEveryToolIsDescribed(): void
    {
        $tools = $this->call('tools/list')['result']['tools'];

        foreach ($tools as $tool) {
            self::assertNotSame('', $tool['description'], $tool['name'] . ' has no description');
            self::assertNotSame([], $tool['inputSchema']['properties'], $tool['name'] . ' describes no parameters');
        }
    }

    // ------------------------------------------- ids that are not numbers

    /** @return iterable<string, array{string}> */
    public static function toolsThatTakeAnEventId(): iterable
    {
        yield 'get_event' => ['get_event'];
        yield 'update_event' => ['update_event'];
        yield 'delete_event' => ['delete_event'];
    }

    #[DataProvider('toolsThatTakeAnEventId')]
    public function testANonNumericIdDoesNotFallThroughToTheFirstEvent(string $tool): void
    {
        // `is_numeric($args['id']) ? (int) $args['id'] : 0` -- the zero is
        // load-bearing. Any other fallback resolves a malformed id to a real
        // event, so a client sending "abc" reads, edits or deletes whichever
        // event happens to hold that id.
        $first = $this->createEvent('Someone elses event', '20260615', '100000');
        self::assertSame(1, $first, 'the fixture relies on the first event getting id 1');

        $body = $this->call('tools/call', [
            'name' => $tool,
            'arguments' => ['id' => 'abc', 'title' => 'Overwritten'],
        ]);

        self::assertArrayHasKey('error', $body, 'a malformed id is not an event');

        $still = $this->call('tools/call', ['name' => 'get_event', 'arguments' => ['id' => $first]]);
        self::assertSame('Someone elses event', $still['result']['event']['title']);
    }

    // ------------------------------------------------ dates a client sends

    public function testAnEventCreatedWithATimeKeepsIt(): void
    {
        // The date and the time are concatenated into one createFromFormat
        // call; losing either operand leaves an unparseable string, and the
        // handler reports an invalid date rather than storing the wrong one.
        $eventId = $this->createEvent('Afternoon', '20260615', '143000');

        $body = $this->call('tools/call', ['name' => 'get_event', 'arguments' => ['id' => $eventId]]);

        self::assertSame('20260615', $body['result']['event']['start_date']);
        self::assertSame('143000', $body['result']['event']['start_time']);
    }

    public function testChangingOnlyTheDateStartsTheEventAtMidnight(): void
    {
        // update_event with a start_date and no start_time re-parses through
        // the date-only branch, which leaves createFromFormat's time at the
        // current clock unless it is snapped. The rebuilt event is not
        // flagged all-day, so that stray time is stored and shown.
        $eventId = $this->createEvent('Afternoon', '20260615', '143000');

        $body = $this->call('tools/call', ['name' => 'update_event', 'arguments' => [
            'id' => $eventId,
            'start_date' => '20260620',
        ]]);

        self::assertSame('20260620', $body['result']['event']['start_date']);
        self::assertSame('000000', $body['result']['event']['start_time']);
    }

    // --------------------------------------------------------- availability

    public function testAvailabilityIsComputedForTheDateThatWasAsked(): void
    {
        // The date is snapped to midnight before it reaches the booking
        // service, which re-times it to office hours anyway -- so only the
        // day survives, and a mutation that rolls past midnight silently
        // answers for the day before.
        $this->createEvent('Blocks the first slot', '20260615', '090000');

        $body = $this->call('tools/call', ['name' => 'get_availability', 'arguments' => [
            'date' => '20260615',
        ]]);

        $starts = array_column($body['result']['available_slots'], 'start');

        self::assertSame('20260615', $body['result']['date']);
        self::assertNotContains('09:00', $starts, 'the 9am slot is taken by the meeting');
        self::assertContains('10:30', $starts, 'the rest of the morning is not');
    }

    public function testAvailabilityForAnUnknownUserAnswersForTheCaller(): void
    {
        // getUserByLogin() returns null for a login nobody has; without the
        // fallback that null goes straight into getAvailability(), which
        // takes a User.
        $body = $this->call('tools/call', ['name' => 'get_availability', 'arguments' => [
            'date' => '20260615',
            'user' => 'nobody-by-that-name',
        ]]);

        self::assertArrayHasKey('result', $body);
        self::assertNotSame([], $body['result']['available_slots']);
    }

    public function testADurationSentAsJsonTextIsStillANumber(): void
    {
        // JSON-RPC arguments arrive as whatever the client encoded, and MCP
        // clients routinely send numbers as strings. is_numeric() accepts
        // "90", but the Event constructor is strict about int -- so the cast
        // is what stands between a quoted number and a TypeError.
        $eventId = $this->createEvent('Meeting', '20260615', '100000');

        $body = $this->call('tools/call', ['name' => 'update_event', 'arguments' => [
            'id' => $eventId,
            'duration' => '90',
        ]]);

        self::assertArrayHasKey('result', $body);
        self::assertSame(90, $body['result']['event']['duration']);
    }

    public function testAvailabilityCanBeAskedForSomeoneElse(): void
    {
        // The `user` argument is the whole point of the tool -- "when is Bob
        // free?" -- and the caller is only its fallback. Swap those two and
        // every such question is answered with the asker's own diary, which
        // reads as a plausible answer rather than an error.
        $bob = new User('bob', 'Bob', 'Jones', 'bob@test.com', false, true);
        $admin = $this->factory->getUserService()->getUserByLogin('admin');
        self::assertNotNull($admin);
        $this->factory->getUserService()->createUser($bob, $admin);

        // Confidential, so it blocks Bob's diary and not everybody's.
        $this->factory->getEventService()->createEvent(new Event(
            id: new EventId(0),
            uid: 'bob-busy@test',
            name: 'Bob is busy',
            description: '',
            location: '',
            start: new \DateTimeImmutable('20260615 090000'),
            duration: 60,
            createdBy: 'bob',
            type: EventType::EVENT,
            access: AccessLevel::CONFIDENTIAL,
        ), $bob);

        $mine = $this->call('tools/call', ['name' => 'get_availability', 'arguments' => [
            'date' => '20260615',
        ]]);
        $bobs = $this->call('tools/call', ['name' => 'get_availability', 'arguments' => [
            'date' => '20260615',
            'user' => 'bob',
        ]]);

        self::assertSame('bob', $bobs['result']['user']);
        self::assertContains('09:00', array_column($mine['result']['available_slots'], 'start'));
        self::assertNotContains('09:00', array_column($bobs['result']['available_slots'], 'start'));
    }

    // ------------------------------------------ what the tool list promises

    /** @return list<array<string, mixed>> */
    private function advertisedTools(): array
    {
        /** @var list<array<string, mixed>> $tools */
        $tools = $this->call('tools/list')['result']['tools'];
        self::assertNotEmpty($tools);

        return $tools;
    }

    public function testEveryToolItAdvertisesCanActuallyBeCalled(): void
    {
        // TOOLS decides both what is advertised and what is allowed past
        // handle(), but callTool() routes on a separate match. A tool added to
        // one and not the other is offered to clients and then answered
        // "Unknown tool". Taking the names from the reply rather than a list
        // written here means a new tool is covered the day it is added.
        foreach ($this->advertisedTools() as $tool) {
            $name = (string) $tool['name'];
            $body = $this->call('tools/call', ['name' => $name, 'arguments' => []]);

            $message = isset($body['error']) ? (string) $body['error']['message'] : '';
            self::assertStringNotContainsString('Unknown tool', $message, $name . ' is advertised but not routed');
        }
    }

    public function testTheDispatchTestsAboveCoverEveryToolThatIsAdvertised(): void
    {
        // Keeps the hand-written provider honest: it is what proves each tool
        // reaches its own handler rather than merely being routed somewhere.
        $advertised = array_map(static fn(array $t): string => (string) $t['name'], $this->advertisedTools());
        $exercised = array_map(static fn(array $case): string => (string) $case[0], iterator_to_array(self::tools()));

        sort($advertised);
        sort($exercised);

        self::assertSame($advertised, $exercised);
    }

    public function testTheAdvertisedSchemaIsTheOneAClientNeedsToCallIt(): void
    {
        // A client builds its call from this schema and nothing else. Only the
        // names were ever checked, so a parameter could have been dropped,
        // renamed, given the wrong type, or quietly stopped being required --
        // and every existing test would still pass while no client could make
        // a valid call.
        $tools = array_column($this->advertisedTools(), null, 'name');

        self::assertSame([
            'type' => 'object',
            'properties' => [
                'start_date' => ['type' => 'string', 'description' => 'Start date YYYYMMDD'],
                'end_date' => ['type' => 'string', 'description' => 'End date YYYYMMDD'],
            ],
            'required' => ['start_date', 'end_date'],
        ], $tools['list_events']['inputSchema']);

        self::assertSame('List calendar events in a date range', $tools['list_events']['description']);
    }

    public function testEveryAdvertisedToolDeclaresASchemaAClientCanRead(): void
    {
        foreach ($this->advertisedTools() as $tool) {
            $name = (string) $tool['name'];

            self::assertArrayHasKey('description', $tool, $name);
            self::assertNotSame('', $tool['description'], $name . ' has no description');

            /** @var array{type: string, properties: array<string, mixed>, required: list<string>} $schema */
            $schema = $tool['inputSchema'];
            self::assertSame('object', $schema['type'], $name);

            // Anything named as required has to be among the properties, or a
            // client is told to send a parameter it has no description for.
            self::assertSame(
                [],
                array_diff($schema['required'], array_keys($schema['properties'])),
                $name . ' requires a parameter it does not describe',
            );
        }
    }
}
