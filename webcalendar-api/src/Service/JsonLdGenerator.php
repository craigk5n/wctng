<?php

declare(strict_types=1);

namespace App\Service;

use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Entity\User;

/**
 * Generates Schema.org Event structured data as JSON-LD.
 */
final class JsonLdGenerator
{
    /**
     * Generates a JSON-LD script block for an event.
     *
     * @param array{lat: float, lon: float}|null $geo Optional geo coordinates
     *
     * @return string JSON-LD <script> tag ready for HTML insertion
     */
    public function generateEventJsonLd(Event $event, User $user, string $canonicalUrl = '', ?array $geo = null): string
    {
        $data = $this->buildEventData($event, $user, $canonicalUrl, $geo);
        $json = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);

        return '<script type="application/ld+json">' . $json . '</script>';
    }

    /**
     * Builds the Schema.org Event data array.
     *
     * @param array{lat: float, lon: float}|null $geo Optional geo coordinates
     *
     * @return array<string, mixed>
     */
    public function buildEventData(Event $event, User $user, string $canonicalUrl = '', ?array $geo = null): array
    {
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Event',
            'name' => $event->name(),
            'startDate' => $event->start()->format(\DateTimeInterface::ATOM),
            'endDate' => $event->end()->format(\DateTimeInterface::ATOM),
            'eventStatus' => $this->mapEventStatus($event->status()),
            'eventAttendanceMode' => $this->mapAttendanceMode($event),
        ];

        // Description — strip HTML tags for plain text in structured data
        $desc = strip_tags($event->description());
        if ($desc !== '') {
            $data['description'] = $desc;
        }

        // Location
        if ($event->location() !== '') {
            $data['location'] = $this->buildLocation($event, $geo);
        }

        // Organizer
        $data['organizer'] = [
            '@type' => 'Person',
            'name' => $user->fullName(),
        ];
        if ($user->email() !== '') {
            $data['organizer']['email'] = $user->email();
        }

        // URL
        if ($canonicalUrl !== '') {
            $data['url'] = $canonicalUrl;
        }

        return $data;
    }

    /**
     * @param array{lat: float, lon: float}|null $geo
     *
     * @return array<string, mixed>
     */
    private function buildLocation(Event $event, ?array $geo = null): array
    {
        $loc = $event->location();

        // Check if location looks like a URL (virtual event)
        if (preg_match('#^https?://#i', $loc)) {
            return [
                '@type' => 'VirtualLocation',
                'url' => $loc,
            ];
        }

        $place = [
            '@type' => 'Place',
            'name' => $loc,
        ];

        if ($geo !== null) {
            $place['geo'] = [
                '@type' => 'GeoCoordinates',
                'latitude' => $geo['lat'],
                'longitude' => $geo['lon'],
            ];
        }

        return $place;
    }

    private function mapEventStatus(?string $status): string
    {
        return match ($status) {
            'CANCELLED', 'cancelled', 'rejected' => 'https://schema.org/EventCancelled',
            'TENTATIVE', 'tentative', 'needs_approval' => 'https://schema.org/EventPostponed',
            default => 'https://schema.org/EventScheduled',
        };
    }

    private function mapAttendanceMode(Event $event): string
    {
        $loc = $event->location();

        if ($loc === '') {
            return 'https://schema.org/OfflineEventAttendanceMode';
        }

        if (preg_match('#^https?://#i', $loc)) {
            return 'https://schema.org/OnlineEventAttendanceMode';
        }

        return 'https://schema.org/OfflineEventAttendanceMode';
    }
}
