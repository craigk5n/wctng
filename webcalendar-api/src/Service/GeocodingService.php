<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Geocodes location text to coordinates using OpenStreetMap Nominatim API.
 * Respects Nominatim usage policy: max 1 request/second, identifies via User-Agent.
 */
final class GeocodingService
{
    private const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/search';
    private const USER_AGENT = 'WebCalendar-NG/1.0 (https://github.com/craigk5n/webcalendar)';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly GeoRepository $geoRepository,
        private readonly CoreServiceFactory $factory,
    ) {}

    /**
     * Geocode a location string and return coordinates.
     *
     * @return array{lat: float, lon: float}|null
     */
    public function geocode(string $location): ?array
    {
        $location = trim($location);
        if ($location === '' || $this->isUnGeocodable($location)) {
            return null;
        }

        try {
            $response = $this->httpClient->request('GET', self::NOMINATIM_URL, [
                'query' => [
                    'q' => $location,
                    'format' => 'json',
                    'limit' => '1',
                ],
                'headers' => [
                    'User-Agent' => self::USER_AGENT,
                    'Accept' => 'application/json',
                ],
                'timeout' => 5,
            ]);

            /** @var list<array{lat: string, lon: string}> $results */
            $results = $response->toArray();

            if (\count($results) === 0) {
                return null;
            }

            return [
                'lat' => (float) $results[0]['lat'],
                'lon' => (float) $results[0]['lon'],
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Geocode an event's location and store the coordinates.
     * Only runs when ENABLE_GEOCODING is Y.
     */
    public function geocodeEvent(int $eventId, string $location): void
    {
        if (!$this->isGeocodingEnabled()) {
            return;
        }

        $location = trim($location);
        if ($location === '' || $this->isUnGeocodable($location)) {
            $this->geoRepository->clearCoordinates($eventId);
            return;
        }

        $coords = $this->geocode($location);
        if ($coords !== null) {
            $this->geoRepository->saveCoordinates($eventId, $coords['lat'], $coords['lon']);
        }
    }

    public function isGeocodingEnabled(): bool
    {
        $value = $this->factory->getConfigService()->getSetting('ENABLE_GEOCODING');
        return $value === 'Y';
    }

    /**
     * Quick check for locations that are unlikely to geocode (URLs, room names, etc.).
     */
    private function isUnGeocodable(string $location): bool
    {
        // URLs (Zoom, Teams, etc.)
        if (preg_match('#^https?://#i', $location)) {
            return true;
        }

        // Very short strings like "Room A", "TBD"
        if (\strlen($location) < 5) {
            return true;
        }

        return false;
    }
}
