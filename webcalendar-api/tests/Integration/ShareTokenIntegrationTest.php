<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Share\ShareTokenRepository;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;

final class ShareTokenIntegrationTest extends IntegrationTestCase
{
    public function testShareTokenFlowCreateFetchRevoke(): void
    {
        $tokenRepo = new ShareTokenRepository($this->pdo);
        $eventService = $this->factory->getEventService();

        // Create an event
        $eventService->createEvent(new Event(
            id: new EventId(0),
            uid: 'share-test@test',
            name: 'Shared Event',
            description: '',
            location: '',
            start: new \DateTimeImmutable('2026-06-01 10:00:00'),
            duration: 60,
            createdBy: 'alice',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
        ), $this->normalUser);

        // Create share token
        $token = $tokenRepo->create('test-share-token', 'alice', null);
        $this->assertSame('test-share-token', $token->token());
        $this->assertSame('alice', $token->ownerLogin());

        // Fetch by token
        $found = $tokenRepo->findByToken('test-share-token');
        $this->assertNotNull($found);
        $this->assertFalse($found->isExpired(new \DateTimeImmutable()));

        // Fetch shared events using the token owner
        $range = new DateRange(
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-30'),
        );
        $events = $this->factory->getEventRepository()->findByDateRange($range, null, 'P', ['alice']);
        $this->assertGreaterThan(0, \count($events));

        // Revoke
        $deleted = $tokenRepo->delete('test-share-token', 'alice');
        $this->assertTrue($deleted);

        // Verify gone
        $this->assertNull($tokenRepo->findByToken('test-share-token'));
    }

    public function testExpiredTokenDetected(): void
    {
        $tokenRepo = new ShareTokenRepository($this->pdo);

        $token = $tokenRepo->create('expired-token', 'alice', '2020-01-01 00:00:00');
        $this->assertTrue($token->isExpired(new \DateTimeImmutable()));
    }
}
