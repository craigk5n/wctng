<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\CoreServiceFactory;
use App\Service\GeocodingService;
use App\Service\GeoRepository;
use App\Tests\Integration\IntegrationTestCase;
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

        $this->service = new GeocodingService($this->httpClient, $this->geoRepo, $this->factory);
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
        $service = new GeocodingService($this->httpClient, $this->geoRepo, $this->factory);

        // Should not call HTTP client
        $this->httpClient->expects($this->never())->method('request');

        $service->geocodeEvent(1, '123 Main St, New York');
    }
}
