<?php

declare(strict_types=1);

namespace App\Service;

use WebCalendar\Core\Domain\Entity\AbstractEntry;

/**
 * Which entries a public surface may show.
 *
 * Every public read path filters on cal_access and nothing else, so an entry's
 * status has never had any bearing on whether the world sees it. Three states
 * mean it should not: one an administrator has not reviewed yet, one they
 * refused, and one whose owner deleted it -- DeleteEventController soft-deletes
 * by writing 'cancelled', so a deleted event stayed on its owner's public
 * calendar and in the sitemap.
 *
 * The same three are what ReminderService already declines to send reminders
 * for, plus the approval state the admin queue is built around.
 */
final class PublishedEvents
{
    /**
     * Lower-cased: the API writes these in lower case, but an import or
     * BookingService writes RFC 5545's own spelling in upper.
     */
    private const WITHHELD = ['needs_approval', 'rejected', 'cancelled'];

    public static function isPublished(AbstractEntry $entry): bool
    {
        $status = $entry->status();

        return $status === null || !\in_array(strtolower($status), self::WITHHELD, true);
    }

    /**
     * @template T of AbstractEntry
     *
     * @param array<array-key, T> $entries
     *
     * @return list<T>
     */
    public static function only(array $entries): array
    {
        return array_values(array_filter($entries, self::isPublished(...)));
    }
}
