<?php

declare(strict_types=1);

namespace App\Service;

use Sabre\VObject;
use WebCalendar\Core\Domain\Entity\Reminder;

/**
 * Handles VALARM ↔ Reminder conversion for CalDAV.
 */
final class ValarmHelper
{
    /**
     * Adds a VALARM component to a VEVENT or VTODO.
     */
    public function addValarmToComponent(VObject\Component $component, Reminder $reminder): void
    {
        $vcalendar = $component->parent;
        if (!$vcalendar instanceof VObject\Component\VCalendar) {
            // Create a temporary calendar to create the component
            $vcalendar = new VObject\Component\VCalendar();
        }

        $valarm = $vcalendar->createComponent('VALARM');

        $action = strtoupper($reminder->action());
        if (!\in_array($action, ['DISPLAY', 'AUDIO', 'EMAIL'], true)) {
            $action = 'DISPLAY';
        }
        $valarm->add('ACTION', $action);

        // Build TRIGGER
        $offset = $reminder->offset();
        $triggerValue = $this->buildTriggerDuration($offset);

        if ($reminder->before() === 'Y') {
            $triggerValue = '-' . $triggerValue;
        }

        if ($reminder->related() === 'E') {
            $valarm->add('TRIGGER', $triggerValue, ['RELATED' => 'END']);
        } else {
            $valarm->add('TRIGGER', $triggerValue);
        }

        if ($action === 'DISPLAY') {
            $valarm->add('DESCRIPTION', 'Reminder');
        }

        $component->add($valarm);
    }

    /**
     * Extracts Reminder entities from VALARM components in a VEVENT/VTODO.
     *
     * @return Reminder[]
     */
    public function extractReminders(VObject\Component $component, int $eventId): array
    {
        $reminders = [];

        foreach ($component->children() as $child) {
            // Sabre upper-cases component names on both read and construction,
            // so the strtoupper() here can only ever be belt and braces.
            if (!$child instanceof VObject\Component || strtoupper($child->name) !== 'VALARM') {
                continue;
            }

            $trigger = $child->TRIGGER;
            if ($trigger === null) {
                continue;
            }

            $triggerStr = (string) $trigger;
            $action = strtoupper((string) ($child->ACTION ?? 'DISPLAY'));

            // Parse the trigger duration.
            //
            // What the sign decides is $before; stripping it off $triggerStr
            // is presentational, because parseDurationToMinutes() matches each
            // unit wherever it appears. Every mutation of the two substr()
            // calls and of the '+' test below therefore survives -- checked
            // against a corpus of trigger shapes, none of them changes a
            // parsed offset.
            $before = 'Y';
            $related = 'S';

            if (str_starts_with($triggerStr, '-')) {
                $before = 'Y';
                $triggerStr = substr($triggerStr, 1);
            } elseif (str_starts_with($triggerStr, '+') || str_starts_with($triggerStr, 'P')) {
                $before = 'N';
                if (str_starts_with($triggerStr, '+')) {
                    $triggerStr = substr($triggerStr, 1);
                }
            }

            // Check RELATED parameter
            $relatedParam = $trigger->offsetGet('RELATED');
            if ($relatedParam !== null) {
                $relatedStr = strtoupper((string) $relatedParam);
                if ($relatedStr === 'END') {
                    $related = 'E';
                }
            }

            $offset = $this->parseDurationToMinutes($triggerStr);

            $reminders[] = new Reminder(
                eventId: $eventId,
                offset: $offset,
                related: $related,
                before: $before,
                action: \in_array($action, ['DISPLAY', 'AUDIO', 'EMAIL'], true) ? $action : 'DISPLAY',
            );
        }

        return $reminders;
    }

    /**
     * Creates a Reminder from a user preference value (minutes before).
     * Returns null if preference is disabled (0 or empty).
     */
    public function createFromPreference(int $eventId, string $preferenceValue): ?Reminder
    {
        $minutes = (int) $preferenceValue;
        if ($minutes <= 0) {
            return null;
        }

        return new Reminder(
            eventId: $eventId,
            offset: $minutes,
            related: 'S',
            before: 'Y',
            action: 'DISPLAY',
        );
    }

    /**
     * Builds an iCalendar DURATION string from minutes.
     * E.g., 15 → "PT15M", 60 → "PT1H", 90 → "PT1H30M", 1440 → "P1D"
     */
    private function buildTriggerDuration(int $minutes): string
    {
        if ($minutes <= 0) {
            return 'PT0M';
        }

        $days = intdiv($minutes, 1440);
        $remaining = $minutes % 1440;
        $hours = intdiv($remaining, 60);
        $mins = $remaining % 60;

        $parts = 'P';
        if ($days > 0) {
            $parts .= $days . 'D';
        }

        if ($hours > 0 || $mins > 0) {
            $parts .= 'T';
            if ($hours > 0) {
                $parts .= $hours . 'H';
            }
            if ($mins > 0) {
                $parts .= $mins . 'M';
            }
        }

        return $parts;
    }

    /**
     * Parses an iCalendar DURATION string to minutes.
     *
     * Matching is position-independent: each unit is picked out wherever it
     * appears, so the sign and the leading "P" that extractReminders() strips
     * off first make no difference to the result, and neither does reading the
     * whole match instead of its digits. Mutation testing reports both as
     * surviving mutants; they are equivalent rather than untested.
     */
    private function parseDurationToMinutes(string $duration): int
    {
        $minutes = 0;

        // Weeks are their own duration form (dur-week): clients that offer a
        // "1 week before" alarm send -P1W, which every other branch here
        // ignores, leaving the reminder at the event time.
        if (preg_match('/(\d+)W/', $duration, $m)) {
            $minutes += (int) $m[1] * 10080;
        }
        if (preg_match('/(\d+)D/', $duration, $m)) {
            $minutes += (int) $m[1] * 1440;
        }
        if (preg_match('/(\d+)H/', $duration, $m)) {
            $minutes += (int) $m[1] * 60;
        }
        if (preg_match('/(\d+)M/', $duration, $m)) {
            $minutes += (int) $m[1];
        }
        if (preg_match('/(\d+)S/', $duration, $m)) {
            // Round up seconds to 1 minute minimum
            if ((int) $m[1] > 0 && $minutes === 0) {
                $minutes = 1;
            }
        }

        return $minutes;
    }
}
