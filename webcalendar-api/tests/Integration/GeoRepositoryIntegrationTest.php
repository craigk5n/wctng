<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\GeoRepository;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;

final class GeoRepositoryIntegrationTest extends IntegrationTestCase
{
    private GeoRepository $geoRepo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->geoRepo = new GeoRepository($this->pdo);
    }

    private function createEvent(string $title): int
    {
        $event = new Event(
            id: new EventId(0),
            uid: 'geo-' . bin2hex(random_bytes(4)) . '@test',
            name: $title,
            description: '',
            location: '123 Main St, New York',
            start: new \DateTimeImmutable('2026-06-15 10:00:00'),
            duration: 60,
            createdBy: 'alice',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
        );
        $this->factory->getEventService()->createEvent($event, $this->normalUser);
        $created = $this->factory->getEventRepository()->findByUid($event->uid());
        return $created !== null ? $created->id()->value() : 0;
    }

    public function testReturnsNullWhenNoCoordinates(): void
    {
        $id = $this->createEvent('No Geo');
        $this->assertNull($this->geoRepo->getCoordinates($id));
    }

    public function testSaveAndRetrieveCoordinates(): void
    {
        $id = $this->createEvent('Geo Event');
        $this->geoRepo->saveCoordinates($id, 40.7128, -74.0060);

        $coords = $this->geoRepo->getCoordinates($id);
        $this->assertNotNull($coords);
        $this->assertEqualsWithDelta(40.7128, $coords['lat'], 0.0001);
        $this->assertEqualsWithDelta(-74.006, $coords['lon'], 0.0001);
    }

    public function testClearCoordinates(): void
    {
        $id = $this->createEvent('Clear Geo');
        $this->geoRepo->saveCoordinates($id, 51.5074, -0.1278);
        $this->assertNotNull($this->geoRepo->getCoordinates($id));

        $this->geoRepo->clearCoordinates($id);
        $this->assertNull($this->geoRepo->getCoordinates($id));
    }

    public function testBatchLoadCoordinates(): void
    {
        $id1 = $this->createEvent('Batch 1');
        $id2 = $this->createEvent('Batch 2');
        $id3 = $this->createEvent('Batch 3 No Geo');

        $this->geoRepo->saveCoordinates($id1, 40.7128, -74.006);
        $this->geoRepo->saveCoordinates($id2, 48.8566, 2.3522);

        $batch = $this->geoRepo->getCoordinatesBatch([$id1, $id2, $id3]);

        $this->assertCount(2, $batch);
        $this->assertArrayHasKey($id1, $batch);
        $this->assertArrayHasKey($id2, $batch);
        $this->assertArrayNotHasKey($id3, $batch);
        $this->assertEqualsWithDelta(48.8566, $batch[$id2]['lat'], 0.0001);
    }

    public function testBatchEmptyIds(): void
    {
        $this->assertSame([], $this->geoRepo->getCoordinatesBatch([]));
    }
}
