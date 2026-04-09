<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Narrow contract for publishing calendar-wide Mercure messages.
 *
 * Extracted so services that only need to emit bulk calendar events
 * (like the admin purge) depend on a small interface rather than the
 * full MercurePublisher, and can be tested with a lightweight double.
 */
interface CalendarPublisherInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function publishCalendarPurged(array $payload): void;
}
