<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\GeocodingService;
use App\Service\GeoRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class GeocodingServiceTest extends IntegrationTestCase
{
    private HttpClientInterface $httpClient;
    private GeoRepository $geoRepo;
    private GeocodingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->geoRepo = new GeoRepository($this->pdo);

        // Enable geocoding
        $this->factory->getConfigService()->updateSetting('ENABLE_GEOCODING', 'Y');

        $this->service = new GeocodingService($this->httpClient, $this->geoRepo, $this->factory->getConfigService());
    }

    public function testGeocodeReturnsCoordinates(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([
            ['lat' => '40.7128', 'lon' => '-74.0060', 'display_name' => 'New York'],
        ]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with('GET', $this->stringContains('nominatim'), $this->callback(function (array $opts): bool {
                return ($opts['query']['q'] ?? '') === '123 Main St, New York';
            }))
            ->willReturn($response);

        $result = $this->service->geocode('123 Main St, New York');
        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(40.7128, $result['lat'], 0.0001);
        $this->assertEqualsWithDelta(-74.006, $result['lon'], 0.0001);
    }

    public function testGeocodeReturnsNullForEmptyLocation(): void
    {
        $this->assertNull($this->service->geocode(''));
    }

    public function testGeocodeReturnsNullForUrls(): void
    {
        $this->assertNull($this->service->geocode('https://zoom.us/j/123'));
    }

    public function testGeocodeReturnsNullForShortStrings(): void
    {
        $this->assertNull($this->service->geocode('TBD'));
    }

    public function testGeocodeReturnsNullWhenNoResults(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([]);

        $this->httpClient->method('request')->willReturn($response);

        $this->assertNull($this->service->geocode('xyznonexistent12345'));
    }

    public function testGeocodeReturnsNullOnHttpError(): void
    {
        $this->httpClient->method('request')
            ->willThrowException(new \RuntimeException('Network error'));

        $this->assertNull($this->service->geocode('New York'));
    }

    public function testGeocodeEventSavesCoordinates(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([
            ['lat' => '40.7128', 'lon' => '-74.0060'],
        ]);
        $this->httpClient->method('request')->willReturn($response);

        // Create an event to geocode
        $event = new \WebCalendar\Core\Domain\Entity\Event(
            id: new \WebCalendar\Core\Domain\ValueObject\EventId(0),
            uid: 'geo-test@test',
            name: 'Geo Test',
            description: '',
            location: '123 Main St, New York',
            start: new \DateTimeImmutable('2026-06-15 10:00:00'),
            duration: 60,
            createdBy: 'alice',
            type: \WebCalendar\Core\Domain\ValueObject\EventType::EVENT,
            access: \WebCalendar\Core\Domain\ValueObject\AccessLevel::PUBLIC,
        );
        $this->factory->getEventService()->createEvent($event, $this->normalUser);
        $created = $this->factory->getEventRepository()->findByUid('geo-test@test');
        $this->assertNotNull($created);
        $eventId = $created->id()->value();

        $this->service->geocodeEvent($eventId, '123 Main St, New York');

        $coords = $this->geoRepo->getCoordinates($eventId);
        $this->assertNotNull($coords);
        $this->assertEqualsWithDelta(40.7128, $coords['lat'], 0.0001);
    }

    public function testGeocodeEventClearsForEmptyLocation(): void
    {
        // Create event and set coordinates
        $event = new \WebCalendar\Core\Domain\Entity\Event(
            id: new \WebCalendar\Core\Domain\ValueObject\EventId(0),
            uid: 'geo-clear@test',
            name: 'Clear Test',
            description: '',
            location: '',
            start: new \DateTimeImmutable('2026-06-15 10:00:00'),
            duration: 60,
            createdBy: 'alice',
            type: \WebCalendar\Core\Domain\ValueObject\EventType::EVENT,
            access: \WebCalendar\Core\Domain\ValueObject\AccessLevel::PUBLIC,
        );
        $this->factory->getEventService()->createEvent($event, $this->normalUser);
        $created = $this->factory->getEventRepository()->findByUid('geo-clear@test');
        $this->assertNotNull($created);
        $eventId = $created->id()->value();

        // Set some coordinates first
        $this->geoRepo->saveCoordinates($eventId, 40.0, -74.0);
        $this->assertNotNull($this->geoRepo->getCoordinates($eventId));

        // Geocode with empty location should clear
        $this->service->geocodeEvent($eventId, '');
        $this->assertNull($this->geoRepo->getCoordinates($eventId));
    }

    public function testGeocodeEventSkipsWhenDisabled(): void
    {
        $this->factory->getConfigService()->updateSetting('ENABLE_GEOCODING', 'N');

        // Re-create service with updated config
        $service = new GeocodingService($this->httpClient, $this->geoRepo, $this->factory->getConfigService());

        // Should not call HTTP client
        $this->httpClient->expects($this->never())->method('request');

        $service->geocodeEvent(1, '123 Main St, New York');
    }

    // ------------------------------------- nothing ungeocodable reaches the API

    /** @return iterable<string, array{string}> */
    public static function locationsNotWorthAsking(): iterable
    {
        // Nominatim's usage policy caps callers at one request a second, so
        // not asking is the point -- and every one of these cases previously
        // "passed" for the wrong reason: with the guard removed the request
        // goes out against a mock with no return value, toArray() raises, the
        // catch swallows it and the method still answers null.
        yield 'empty' => [''];
        yield 'whitespace only' => ['     '];
        yield 'too short' => ['TBD'];
        yield 'four characters' => ['Room'];
        yield 'a zoom link' => ['https://zoom.us/j/1234567890'];
        yield 'a teams link' => ['http://teams.microsoft.com/l/meetup-join/abc'];
        yield 'an upper case link' => ['HTTPS://ZOOM.US/J/1234567890'];
        yield 'a mixed case scheme' => ['HtTpS://zoom.us/j/1234567890'];
    }

    #[DataProvider('locationsNotWorthAsking')]
    public function testAnUngeocodableLocationIsNeverSentToNominatim(string $location): void
    {
        $this->httpClient->expects($this->never())->method('request');

        $this->assertNull($this->service->geocode($location));
    }

    public function testALocationThatMerelyMentionsAUrlIsStillLookedUp(): void
    {
        // The pattern is anchored: it rejects a location that *is* a link, not
        // one that happens to contain one. Without the anchor, "Main Hall, see
        // https://maps.example/x" is thrown away, and so is any address with a
        // URL in the notes.
        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([['lat' => '51.5', 'lon' => '-0.1']]);
        $this->httpClient->expects($this->once())->method('request')->willReturn($response);

        $this->assertNotNull($this->service->geocode('Main Hall, see https://maps.example/x'));
    }

    public function testAFiveCharacterLocationIsLongEnoughToTry(): void
    {
        // Under five characters is dropped, so five is the shortest thing that
        // gets looked up. With the comparison off by one, the shortest real
        // place names -- "Paris", "Tokyo", "Delhi" -- are silently discarded.
        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([['lat' => '48.8566', 'lon' => '2.3522']]);
        $this->httpClient->expects($this->once())->method('request')->willReturn($response);

        $this->assertNotNull($this->service->geocode('Paris'));
    }

    // ------------------------------------------------ how the request is made

    public function testTheLocationIsTrimmedBeforeItIsAskedAbout(): void
    {
        // A location pasted out of a calendar invite often carries padding.
        // Sent as-is it becomes a different cache key and a different query
        // than the same address typed by hand.
        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([['lat' => '40.7128', 'lon' => '-74.0060']]);

        $seen = null;
        $this->httpClient->expects($this->once())
            ->method('request')
            ->with('GET', $this->anything(), $this->callback(function (array $opts) use (&$seen): bool {
                $seen = $opts;

                return true;
            }))
            ->willReturn($response);

        $this->service->geocode("  123 Main St, New York \t ");

        $this->assertIsArray($seen);
        $this->assertSame('123 Main St, New York', $seen['query']['q']);
    }

    public function testTheRequestIdentifiesItselfAndBoundsHowLongItWaits(): void
    {
        // Nominatim's policy requires a User-Agent that identifies the
        // application and blocks callers that omit it, so the header is part
        // of the contract rather than a nicety. The timeout matters just as
        // much: geocoding happens while saving an event, so an unbounded wait
        // is a hung save.
        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([['lat' => '40.7128', 'lon' => '-74.0060']]);

        $seen = null;
        $this->httpClient->expects($this->once())
            ->method('request')
            ->with('GET', $this->anything(), $this->callback(function (array $opts) use (&$seen): bool {
                $seen = $opts;

                return true;
            }))
            ->willReturn($response);

        $this->service->geocode('123 Main St, New York');

        $this->assertIsArray($seen);
        $this->assertStringContainsString('WebCalendar-NG', $seen['headers']['User-Agent']);
        $this->assertSame('application/json', $seen['headers']['Accept']);
        $this->assertSame(5, $seen['timeout']);
        $this->assertSame('json', $seen['query']['format']);
        $this->assertSame('1', $seen['query']['limit']);
    }

    // ------------------------------------------------- storing what came back

    public function testGeocodeEventClearsCoordinatesWhenTheLocationBecomesALink(): void
    {
        // Moving a meeting from an address to a Zoom link has to drop the old
        // pin, or the event keeps showing on the map at wherever it used to
        // be. Only the empty-location case was covered, and that one clears
        // whether the check is an or or an and.
        $eventId = $this->eventWithLocation('geo-relocated@test', 'Somewhere');
        $this->geoRepo->saveCoordinates($eventId, 40.0, -74.0);
        $this->assertNotNull($this->geoRepo->getCoordinates($eventId));

        $this->httpClient->expects($this->never())->method('request');
        $this->service->geocodeEvent($eventId, 'https://zoom.us/j/1234567890');

        $this->assertNull($this->geoRepo->getCoordinates($eventId));
    }

    /** Creates an event and returns its id. */
    private function eventWithLocation(string $uid, string $location): int
    {
        $event = new \WebCalendar\Core\Domain\Entity\Event(
            id: new \WebCalendar\Core\Domain\ValueObject\EventId(0),
            uid: $uid,
            name: 'Geo Test',
            description: '',
            location: $location,
            start: new \DateTimeImmutable('2026-06-15 10:00:00'),
            duration: 60,
            createdBy: 'alice',
            type: \WebCalendar\Core\Domain\ValueObject\EventType::EVENT,
            access: \WebCalendar\Core\Domain\ValueObject\AccessLevel::PUBLIC,
        );
        $this->factory->getEventService()->createEvent($event, $this->normalUser);
        $created = $this->factory->getEventRepository()->findByUid($uid);
        $this->assertNotNull($created);

        return $created->id()->value();
    }

    /** @return iterable<string, array{string}> */
    public static function paddedLocationsThatAreReallyTooShort(): iterable
    {
        // Padded, these clear the five-character bar; trimmed, they do not.
        yield 'padded TBD' => ['  TBD  '];
        yield 'padded room' => ['  Room  '];
        yield 'tab padded' => ["\tN/A\t"];
    }

    #[DataProvider('paddedLocationsThatAreReallyTooShort')]
    public function testAPaddedShortLocationStillClearsTheOldPin(string $location): void
    {
        // The trim in geocodeEvent() is not a duplicate of the one inside
        // geocode(): it decides whether the *clear* happens. Padded, "  TBD  "
        // is nine characters and looks geocodable, so the clear is skipped and
        // geocode() -- which trims for itself -- then declines to look it up.
        // The event keeps whatever pin it had, still on the map at an address
        // it no longer has.
        $eventId = $this->eventWithLocation('geo-padded-' . md5($location) . '@test', 'Somewhere');
        $this->geoRepo->saveCoordinates($eventId, 40.0, -74.0);
        $this->assertNotNull($this->geoRepo->getCoordinates($eventId));

        $this->httpClient->expects($this->never())->method('request');
        $this->service->geocodeEvent($eventId, $location);

        $this->assertNull($this->geoRepo->getCoordinates($eventId));
    }
}
