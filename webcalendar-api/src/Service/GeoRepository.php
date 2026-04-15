<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Direct PDO access to geo coordinate columns in webcal_entry.
 * Bypasses webcalendar-core's Event entity which doesn't expose geo data.
 */
class GeoRepository
{
    public function __construct(
        private readonly \PDO $pdo,
    ) {}

    /**
     * @return array{lat: float, lon: float}|null
     */
    public function getCoordinates(int $eventId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT cal_geo_lat, cal_geo_lon FROM webcal_entry WHERE cal_id = :id',
        );
        $stmt->execute(['id' => $eventId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!\is_array($row) || $row['cal_geo_lat'] === null || $row['cal_geo_lon'] === null) {
            return null;
        }

        /** @var numeric-string $lat */
        $lat = $row['cal_geo_lat'];
        /** @var numeric-string $lon */
        $lon = $row['cal_geo_lon'];

        return [
            'lat' => (float) $lat,
            'lon' => (float) $lon,
        ];
    }

    public function saveCoordinates(int $eventId, float $lat, float $lon): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE webcal_entry SET cal_geo_lat = :lat, cal_geo_lon = :lon WHERE cal_id = :id',
        );
        $stmt->execute(['lat' => $lat, 'lon' => $lon, 'id' => $eventId]);
    }

    public function clearCoordinates(int $eventId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE webcal_entry SET cal_geo_lat = NULL, cal_geo_lon = NULL WHERE cal_id = :id',
        );
        $stmt->execute(['id' => $eventId]);
    }

    /**
     * Batch load coordinates for multiple event IDs.
     *
     * @param list<int> $eventIds
     *
     * @return array<int, array{lat: float, lon: float}>
     */
    public function getCoordinatesBatch(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach ($eventIds as $i => $id) {
            $key = 'id' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }

        $sql = 'SELECT cal_id, cal_geo_lat, cal_geo_lon FROM webcal_entry WHERE cal_id IN (' . implode(', ', $placeholders) . ') AND cal_geo_lat IS NOT NULL AND cal_geo_lon IS NOT NULL';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $result = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            if (\is_array($row)) {
                /** @var numeric-string $id */
                $id = $row['cal_id'];
                /** @var numeric-string $lat */
                $lat = $row['cal_geo_lat'];
                /** @var numeric-string $lon */
                $lon = $row['cal_geo_lon'];
                $result[(int) $id] = [
                    'lat' => (float) $lat,
                    'lon' => (float) $lon,
                ];
            }
        }

        return $result;
    }
}
