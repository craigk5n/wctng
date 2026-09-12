<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\PublishedEvents;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;

/**
 * Which entries a public surface may show.
 *
 * Every public read path filtered on cal_access alone, so this rule did not
 * exist anywhere: an entry awaiting approval, one an administrator refused,
 * and one its owner had deleted were all still access 'P' and all still
 * published.
 */
final class PublishedEventsTest extends TestCase
{
    private static function withStatus(?string $status): Event
    {
        return new Event(
            id: new EventId(1),
            uid: 'e@x',
            name: 'Quarterly review',
            description: '',
            location: '',
            start: new \DateTimeImmutable('2026-10-01 10:00:00'),
            duration: 60,
            createdBy: 'alice',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
            status: $status,
        );
    }

    #[DataProvider('withheldStatuses')]
    public function testAnEntryInOneOfTheWithheldStatesIsNotPublished(string $status): void
    {
        $this->assertFalse(PublishedEvents::isPublished(self::withStatus($status)));
    }

    /** @return iterable<string, array{string}> */
    public static function withheldStatuses(): iterable
    {
        yield 'waiting for an administrator' => ['needs_approval'];
        yield 'refused by an administrator' => ['rejected'];
        // DeleteEventController soft-deletes by writing this.
        yield 'deleted by its owner' => ['cancelled'];
        // An import or BookingService writes RFC 5545's own spelling.
        yield 'refused, shouted' => ['REJECTED'];
        yield 'deleted, shouted' => ['CANCELLED'];
        yield 'waiting, shouted' => ['NEEDS_APPROVAL'];
    }

    #[DataProvider('publishedStatuses')]
    public function testEverythingElseIsPublished(?string $status): void
    {
        $this->assertTrue(PublishedEvents::isPublished(self::withStatus($status)));
    }

    /** @return iterable<string, array{string|null}> */
    public static function publishedStatuses(): iterable
    {
        // The overwhelming majority of rows: cal_status defaults to NULL.
        yield 'never given one' => [null];
        yield 'approved' => ['confirmed'];
        // RFC 5545's own "not settled yet", which a user may set deliberately
        // and an import carries over. Not an approval state.
        yield 'tentative' => ['tentative'];
        yield 'tentative, shouted' => ['TENTATIVE'];
        yield 'something nobody recognises' => ['whatever'];
        yield 'empty' => [''];
    }

    public function testOnlyKeepsThePublishedOnesInOrder(): void
    {
        $kept = PublishedEvents::only([
            self::withStatus('confirmed'),
            self::withStatus('needs_approval'),
            self::withStatus(null),
            self::withStatus('cancelled'),
            self::withStatus('tentative'),
        ]);

        $this->assertSame(
            ['confirmed', null, 'tentative'],
            array_map(static fn(Event $e): ?string => $e->status(), $kept),
        );
    }

    public function testOnlyReturnsAListRatherThanAnArrayWithGaps(): void
    {
        // The callers page through the result with array_slice and count it;
        // holes left by the filter would make the first page short.
        $kept = PublishedEvents::only([
            self::withStatus('cancelled'),
            self::withStatus('confirmed'),
        ]);

        $this->assertSame([0], array_keys($kept));
    }

    public function testNothingInMeansNothingOut(): void
    {
        $this->assertSame([], PublishedEvents::only([]));
    }
}
