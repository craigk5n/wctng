<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Api\BookingController;
use App\Tests\Unit\EventSubscriber\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use WebCalendar\Core\Application\Contract\RateLimiterInterface;
use WebCalendar\Core\Application\Service\BookingService;
use WebCalendar\Core\Application\Service\EventService;
use WebCalendar\Core\Application\Service\UserService;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\Repository\UserRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\UserPreference;

/**
 * Public booking -- the two routes anyone on the internet can reach.
 *
 * `^/api/v2/public/` is `security: false`, so neither of these has a caller to
 * check. Everything that stands between the open internet and a write to
 * somebody's calendar is in this file, and nothing executed a line of it.
 * PublicCalendarController and FeedController sit behind the same firewall and
 * already answer the question of what that has to look like: a rate limit, an
 * opt-in preference, and one indistinguishable 404 for "no such user" and
 * "that user has not opted in".
 */
final class BookingControllerTest extends TestCase
{
    private UserRepositoryInterface&MockObject $userRepo;
    private EventRepositoryInterface&MockObject $eventRepo;
    private RateLimiterInterface&MockObject $rateLimiter;
    /** @var list<array{identifier: string, action: string, max: int, window: int}> */
    private array $limitChecks = [];
    private RecordingLogger $logger;
    /** @var list<Event> */
    private array $saved = [];
    /** @var list<Event> */
    private array $existing = [];

    #[\Override]
    protected function setUp(): void
    {
        $this->userRepo = $this->createMock(UserRepositoryInterface::class);
        $this->eventRepo = $this->createMock(EventRepositoryInterface::class);
        $this->rateLimiter = $this->createMock(RateLimiterInterface::class);
        $this->logger = new RecordingLogger();
        $this->saved = [];
        $this->existing = [];

        $this->limitChecks = [];
        $this->rateLimiter->method('isAllowed')->willReturnCallback(
            function (string $identifier, string $action, int $max, int $window): bool {
                $this->limitChecks[] = ['identifier' => $identifier, 'action' => $action, 'max' => $max, 'window' => $window];

                return true;
            },
        );
        $this->eventRepo->method('save')->willReturnCallback(function (Event $event): void {
            $this->saved[] = $event;
        });
        $this->eventRepo->method('findByDateRange')->willReturnCallback(fn(): array => $this->existing);
    }

    /** Nobody with that login. */
    private function noSuchUser(): void
    {
        $this->userRepo->method('findByLogin')->willReturn(null);
        $this->userRepo->method('getPreferences')->willReturn([]);
    }

    /** @param list<UserPreference> $preferences */
    private function userExists(bool $enabled = true, bool $acceptsBookings = true): void
    {
        $this->userRepo->method('findByLogin')->willReturn(
            new User('alice', 'Alice', 'Smith', 'alice@example.com', false, $enabled),
        );
        $this->userRepo->method('getPreferences')->willReturn(
            $acceptsBookings ? [new UserPreference('public_calendar_enabled', 'Y')] : [],
        );
    }

    private function controller(): BookingController
    {
        $events = new EventService($this->eventRepo, $this->userRepo);

        return new BookingController(
            new UserService($this->userRepo),
            new BookingService($events),
            $this->userRepo,
            $this->rateLimiter,
            $this->logger,
        );
    }

    private static function availabilityRequest(string $query = ''): Request
    {
        return Request::create('/api/v2/public/availability/alice?' . $query);
    }

    private static function bookRequest(mixed $body): Request
    {
        $content = \is_string($body) ? $body : json_encode($body, \JSON_THROW_ON_ERROR);

        return Request::create('/api/v2/public/book/alice', 'POST', [], [], [], [], $content);
    }

    /** @return array<string, mixed> */
    private static function payload(JsonResponse $response): array
    {
        $body = $response->getContent();
        self::assertIsString($body);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /** @return array<string, mixed> */
    private static function goodBooking(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Bob Jones',
            'email' => 'bob@example.com',
            'date' => '2026-10-01',
            'time' => '10:00',
        ];
    }

    // ------------------------------------------------------ who can be booked

    /**
     * Publishing nothing and not existing have to look the same from outside.
     * They did not: an unknown login answered 404 and a real one carried on to
     * a 400 about the date, which turns the route into a login oracle anyone
     * can walk.
     */
    #[DataProvider('routesThatNameAUser')]
    public function testAUserWhoHasNotOptedInIsIndistinguishableFromOneWhoDoesNotExist(\Closure $call): void
    {
        $this->userExists(acceptsBookings: false);

        $response = $call($this->controller());

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('User not found', self::payload($response)['error']['message']);
        $this->assertSame([], $this->saved);
    }

    #[DataProvider('routesThatNameAUser')]
    public function testAnUnknownUserIsRefused(\Closure $call): void
    {
        $this->noSuchUser();

        $response = $call($this->controller());

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('User not found', self::payload($response)['error']['message']);
        $this->assertSame([], $this->saved);
    }

    #[DataProvider('routesThatNameAUser')]
    public function testADisabledAccountCannotBeBooked(\Closure $call): void
    {
        $this->userExists(enabled: false);

        $response = $call($this->controller());

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame([], $this->saved);
    }

    /** @return iterable<string, array{\Closure}> */
    public static function routesThatNameAUser(): iterable
    {
        yield 'availability' => [static fn(BookingController $c): JsonResponse
            => $c->availability('alice', self::availabilityRequest('date=2026-10-01'))];
        yield 'book' => [static fn(BookingController $c): JsonResponse
            => $c->book('alice', self::bookRequest(self::goodBooking()))];
    }

    // ------------------------------------------------------------ rate limits

    #[DataProvider('routesThatNameAUser')]
    public function testBothRoutesAreRateLimited(\Closure $call): void
    {
        // Neither has a caller to hold responsible, so the address is all
        // there is; without this the write route is an open spam funnel into
        // somebody's calendar.
        $this->userExists();
        $this->rateLimiter = $this->createMock(RateLimiterInterface::class);
        $this->rateLimiter->method('isAllowed')->willReturn(false);

        $response = $call($this->controller());

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame([], $this->saved);
    }

    public function testAnAllowedRequestIsCountedAgainstTheLimit(): void
    {
        $this->userExists();
        $this->rateLimiter = $this->createMock(RateLimiterInterface::class);
        $this->limitChecks = [];
        $this->rateLimiter->method('isAllowed')->willReturnCallback(
            function (string $identifier, string $action, int $max, int $window): bool {
                $this->limitChecks[] = ['identifier' => $identifier, 'action' => $action, 'max' => $max, 'window' => $window];

                return true;
            },
        );
        $this->rateLimiter->expects($this->once())->method('recordAttempt');

        $this->controller()->book('alice', self::bookRequest(self::goodBooking()));
    }

    // ---------------------------------------------------------- availability

    public function testAvailabilityListsTheFreeSlotsOfTheWorkingDay(): void
    {
        $this->userExists();

        $data = self::payload($this->controller()->availability('alice', self::availabilityRequest('date=2026-10-01')))['data'];

        $this->assertSame('2026-10-01', $data['date']);
        $this->assertSame('alice', $data['username']);
        // Nine to five in half hours.
        $this->assertCount(16, $data['slots']);
        $this->assertSame(['start' => '09:00', 'end' => '09:30'], $data['slots'][0]);
        $this->assertSame(['start' => '16:30', 'end' => '17:00'], $data['slots'][15]);
    }

    public function testAvailabilityLeavesOutSlotsThatAreAlreadyTaken(): void
    {
        $this->userExists();
        $this->existing = [self::event('Standup', '2026-10-01 09:00:00', 60)];

        $data = self::payload($this->controller()->availability('alice', self::availabilityRequest('date=2026-10-01')))['data'];

        // Fourteen: a slot that begins exactly when the appointment ends is
        // free. DateRange::overlaps() read intervals as closed until
        // webcalendar-core v4.11.0, which cost the slot after every
        // appointment.
        $this->assertCount(14, $data['slots']);
        $this->assertSame(['start' => '10:00', 'end' => '10:30'], $data['slots'][0]);
    }

    #[DataProvider('unusableAvailabilityDates')]
    public function testAvailabilityRefusesADateItCannotAnswerFor(string $query, string $message): void
    {
        $this->userExists();

        $response = $this->controller()->availability('alice', self::availabilityRequest($query));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame($message, self::payload($response)['error']['message']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function unusableAvailabilityDates(): iterable
    {
        $missing = 'Missing required query param: date (YYYY-MM-DD)';
        $invalid = 'Invalid date format. Expected YYYY-MM-DD.';

        yield 'no date' => ['', $missing];
        yield 'empty date' => ['date=', $missing];
        yield 'words' => ['date=tomorrow', $invalid];
        // createFromFormat rolls an impossible date forward, and the reply
        // still carries the date that was asked for.
        yield 'the 31st of February' => ['date=2026-02-31', $invalid];
        yield 'the 13th month' => ['date=2026-13-45', $invalid];
    }

    // ------------------------------------------------------------------ book

    public function testABookingLandsOnTheCalendarItNamed(): void
    {
        $this->userExists();

        $response = $this->controller()->book('alice', self::bookRequest(self::goodBooking()));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(
            ['message' => 'Booking confirmed', 'date' => '2026-10-01', 'time' => '10:00', 'duration' => 30],
            self::payload($response)['data'],
        );

        $this->assertCount(1, $this->saved);
        $event = $this->saved[0];
        $this->assertSame('Booking: Bob Jones', $event->name());
        // Nobody authenticated this, so it waits for the owner rather than
        // appearing on the calendar -- and the public read paths hold back
        // anything in this state.
        $this->assertSame('needs_approval', $event->status());
        $this->assertSame('2026-10-01 10:00:00', $event->start()->format('Y-m-d H:i:s'));
        $this->assertSame(30, $event->duration());
        $this->assertSame('alice', $event->createdBy());
    }

    public function testABookingCanAskForALongerSlot(): void
    {
        $this->userExists();

        $response = $this->controller()->book('alice', self::bookRequest(self::goodBooking(['duration' => 90])));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(90, $this->saved[0]->duration());
    }

    #[DataProvider('rejectedBookings')]
    public function testABookingThatIsNotOneIsRefused(mixed $body, ?string $message = null): void
    {
        $this->userExists();

        $response = $this->controller()->book('alice', self::bookRequest($body));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $this->saved, 'nothing may reach the calendar from a refused booking');

        // Which field is wrong is the only useful thing a 400 here can say, and
        // every one of these ends in a 400 by some route or other.
        if ($message !== null) {
            $this->assertSame($message, self::payload($response)['error']['message']);
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function rejectedBookings(): iterable
    {
        $missing = 'Missing required fields: name, email, date, time';
        $badStamp = 'Invalid date/time format';

        yield 'no name' => [['email' => 'b@x.com', 'date' => '2026-10-01', 'time' => '10:00'], $missing];
        yield 'no email' => [['name' => 'Bob', 'date' => '2026-10-01', 'time' => '10:00'], $missing];
        yield 'no date' => [['name' => 'Bob', 'email' => 'b@x.com', 'time' => '10:00'], $missing];
        yield 'no time' => [['name' => 'Bob', 'email' => 'b@x.com', 'date' => '2026-10-01'], $missing];
        yield 'name of spaces' => [self::goodBooking(['name' => '   ']), $missing];
        yield 'name is a number' => [self::goodBooking(['name' => 42]), $missing];
        yield 'email is an array' => [self::goodBooking(['email' => ['b@x.com']]), $missing];
        yield 'date is a number' => [self::goodBooking(['date' => 20261001]), $missing];
        yield 'not an email at all' => [self::goodBooking(['email' => 'not-an-email']), 'Invalid email address'];
        yield 'words for a date' => [self::goodBooking(['date' => 'tomorrow']), $badStamp];
        yield 'words for a time' => [self::goodBooking(['time' => 'noon']), $badStamp];
        // Rolled forward rather than refused: 2026-02-31 10:00 is the 3rd of
        // March, and the reply says the 31st of February.
        yield 'the 31st of February' => [self::goodBooking(['date' => '2026-02-31']), $badStamp];
        yield 'the 25th hour' => [self::goodBooking(['time' => '25:00']), $badStamp];
        yield 'not an object' => ['"just a string"'];
        yield 'not json at all' => ['<html>'];
        yield 'empty body' => [''];
    }

    /**
     * Both strings go straight into an event on someone else's calendar, and
     * the name lands in a VARCHAR(80) behind a nine-character prefix.
     */
    #[DataProvider('oversizedBookings')]
    public function testABookingCannotCarryUnboundedText(array $body): void
    {
        $this->userExists();

        $response = $this->controller()->book('alice', self::bookRequest(self::goodBooking($body)));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $this->saved);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function oversizedBookings(): iterable
    {
        yield 'a very long name' => [['name' => str_repeat('a', 200)]];
        yield 'a very long email' => [['email' => str_repeat('a', 200) . '@example.com']];
    }

    #[DataProvider('unusableDurations')]
    public function testADurationHasToBeOneTheDayCanHold(mixed $duration): void
    {
        // Unbounded, one anonymous request blocks out a year of somebody's
        // calendar; at zero or below it is not a booking at all.
        $this->userExists();

        $response = $this->controller()->book('alice', self::bookRequest(self::goodBooking(['duration' => $duration])));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $this->saved);
        // Refused on the way in, rather than thrown deeper down and caught.
        $this->assertStringNotContainsString('Booking failed', self::payload($response)['error']['message']);
    }

    /** @return iterable<string, array{mixed}> */
    public static function unusableDurations(): iterable
    {
        yield 'none at all' => [0];
        yield 'negative' => [-30];
        yield 'a year' => [525600];
        yield 'longer than a working day' => [481];
    }

    public function testADurationThatIsNotANumberFallsBackToHalfAnHour(): void
    {
        // Optional, so one of the wrong type takes the default rather than
        // refusing an otherwise good booking.
        $this->userExists();

        $response = $this->controller()->book('alice', self::bookRequest(self::goodBooking(['duration' => '90'])));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(30, $this->saved[0]->duration());
    }

    public function testADurationOfExactlyAWorkingDayIsAllowed(): void
    {
        $this->userExists();

        $response = $this->controller()->book('alice', self::bookRequest(self::goodBooking(['duration' => 480])));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(480, $this->saved[0]->duration());
    }

    /**
     * The guard that used to refuse these outright is gone: BookingService
     * takes a sanitizer of its own since webcalendar-core v4.11.0, and the
     * factory hands it the same DescriptionSanitizer every other write path
     * uses. A name with a bracket in it is a name, not an attack -- what
     * matters is that no markup reaches the event.
     */
    #[DataProvider('namesCarryingMarkup')]
    public function testMarkupInANameNeverReachesTheEvent(string $name, string $goneForGood): void
    {
        $this->userExists();

        $response = $this->controller()->book('alice', self::bookRequest(self::goodBooking(['name' => $name])));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertCount(1, $this->saved);

        foreach ([$this->saved[0]->name(), $this->saved[0]->description()] as $stored) {
            // Nothing that can open a tag, and the dangerous word itself gone.
            $this->assertDoesNotMatchRegularExpression('#<[a-zA-Z/!]#', $stored);
            $this->assertStringNotContainsString($goneForGood, $stored);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function namesCarryingMarkup(): iterable
    {
        yield 'a whole tag' => ['<script>alert(1)</script>', 'script'];
        yield 'an unterminated tag' => ['<img src=x onerror=alert(1)', 'onerror'];
        // v4.11.1 closed this one: a browser decodes an unterminated numeric
        // reference, html_entity_decode() does not.
        yield 'an entity without its semicolon' => ['&#60script&#62', '&#60'];
    }

    public function testABracketThatIsNotMarkupIsLeftInTheName(): void
    {
        // "Alice < Bob" is a name, not an attack. It reaches the page escaped,
        // so the sanitizer has no reason to take it apart.
        $this->userExists();

        $response = $this->controller()->book(
            'alice',
            self::bookRequest(self::goodBooking(['name' => 'Alice < Bob'])),
        );

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('Booking: Alice < Bob', $this->saved[0]->name());
    }

    public function testAnEmailOfExactlyTheLimitIsAccepted(): void
    {
        $this->userExists();
        $email = str_repeat('a', 75 - \strlen('@example.com')) . '@example.com';
        $this->assertSame(75, \strlen($email));

        $response = $this->controller()->book('alice', self::bookRequest(self::goodBooking(['email' => $email])));

        $this->assertSame(201, $response->getStatusCode());
    }

    public function testALongEmailIsMeasuredInCharactersNotBytes(): void
    {
        // cal_email is a VARCHAR(75), counted in characters. Measured in bytes
        // an address with accented letters would be refused at half the length
        // the column actually holds.
        $this->userExists();
        $local = str_repeat('é', 60);
        $this->assertSame(60, mb_strlen($local . ''));

        $response = $this->controller()->book(
            'alice',
            self::bookRequest(self::goodBooking(['email' => $local . '@example.com'])),
        );

        // Refused for the format, never for the length: 72 characters fit.
        $this->assertSame('Invalid email address', self::payload($response)['error']['message']);
    }

    public function testANameOfExactlyTheLimitIsAccepted(): void
    {
        $this->userExists();

        $response = $this->controller()->book('alice', self::bookRequest(self::goodBooking(['name' => str_repeat('a', 60)])));

        $this->assertSame(201, $response->getStatusCode());
    }

    public function testALongNameIsMeasuredInCharactersNotBytes(): void
    {
        // cal_name is a VARCHAR(80), and MySQL counts characters there. Sixty
        // accented letters fit it and take 120 bytes; measured in bytes this
        // booking would be refused for being the right size.
        $this->userExists();

        $response = $this->controller()->book('alice', self::bookRequest(self::goodBooking(['name' => str_repeat('é', 60)])));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('Booking: ' . str_repeat('é', 60), $this->saved[0]->name());
    }

    public function testTheShortestBookingIsStillABooking(): void
    {
        $this->userExists();

        $response = $this->controller()->book('alice', self::bookRequest(self::goodBooking(['duration' => 1])));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(1, $this->saved[0]->duration());
    }

    public function testOnlyThePublicPreferenceOpensTheCalendarUp(): void
    {
        // Nearly every account has some preference set to 'Y'. Matching on the
        // value without the key would make all of them publicly bookable.
        $this->userRepo->method('findByLogin')->willReturn(
            new User('alice', 'Alice', 'Smith', 'alice@example.com', false, true),
        );
        $this->userRepo->method('getPreferences')->willReturn([
            new UserPreference('daily_agenda_enabled', 'Y'),
            new UserPreference('public_calendar_enabled', 'N'),
        ]);

        $response = $this->controller()->book('alice', self::bookRequest(self::goodBooking()));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame([], $this->saved);
    }

    public function testTheRateLimitIsKeptPerAddressInItsOwnBucket(): void
    {
        // Shared with another feature's bucket, one busy public endpoint would
        // lock the other out -- and without the address in it, everybody's
        // requests would count against one budget.
        $this->userExists();
        $request = Request::create(
            '/api/v2/public/book/alice',
            'POST',
            [],
            [],
            [],
            ['REMOTE_ADDR' => '203.0.113.7'],
            json_encode(self::goodBooking(), \JSON_THROW_ON_ERROR),
        );

        $this->controller()->book('alice', $request);

        $this->assertCount(1, $this->limitChecks);
        $this->assertSame('public_booking:203.0.113.7', $this->limitChecks[0]['identifier']);
        $this->assertSame('public_booking', $this->limitChecks[0]['action']);
        $this->assertSame(5, $this->limitChecks[0]['max']);
        $this->assertSame(300, $this->limitChecks[0]['window']);
    }

    public function testReadingAvailabilityGetsTheWiderBudget(): void
    {
        $this->userExists();

        $this->controller()->availability('alice', self::availabilityRequest('date=2026-10-01'));

        $this->assertSame(30, $this->limitChecks[0]['max']);
        $this->assertSame(60, $this->limitChecks[0]['window']);
    }

    /**
     * The caller is anonymous, so whatever the storage layer says about itself
     * goes straight to the open internet -- table and column names included.
     */
    public function testAFailureSaysNothingAboutTheDatabase(): void
    {
        $this->userExists();
        $this->eventRepo = $this->createMock(EventRepositoryInterface::class);
        $this->eventRepo->method('save')->willThrowException(
            new \PDOException("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'cal_venue_id' in 'field list'"),
        );

        $response = $this->controller()->book('alice', self::bookRequest(self::goodBooking()));

        $this->assertSame(400, $response->getStatusCode());
        $message = self::payload($response)['error']['message'];
        $this->assertStringNotContainsString('cal_venue_id', $message);
        $this->assertStringNotContainsString('SQLSTATE', $message);
        $this->assertStringNotContainsString('Column not found', $message);

        // Still recorded where an operator can read it.
        $this->assertCount(1, $this->logger->records);
        $this->assertSame('alice', $this->logger->records[0]['context']['user']);
        $this->assertStringContainsString('cal_venue_id', (string) $this->logger->records[0]['context']['exception']);
    }

    private static function event(string $name, string $start, int $duration): Event
    {
        return new Event(
            id: new \WebCalendar\Core\Domain\ValueObject\EventId(1),
            uid: 'e@x',
            name: $name,
            description: '',
            location: '',
            start: new \DateTimeImmutable($start),
            duration: $duration,
            createdBy: 'alice',
            type: \WebCalendar\Core\Domain\ValueObject\EventType::EVENT,
            access: \WebCalendar\Core\Domain\ValueObject\AccessLevel::PUBLIC,
        );
    }
}
