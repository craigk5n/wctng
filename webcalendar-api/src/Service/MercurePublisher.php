<?php

declare(strict_types=1);

namespace App\Service;

use App\Tenant\TenantContext;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Publishes calendar event changes to the Mercure hub.
 *
 * Topics are tenant-scoped in multi-tenant mode:
 *   /tenants/{slug}/calendars/events/{eventId}
 * In standalone mode:
 *   /calendars/events/{eventId}
 */
final class MercurePublisher
{
    public function __construct(
        private readonly HubInterface $hub,
        private readonly TenantContext $tenantContext,
    ) {
    }

    /**
     * @param array<string, mixed> $eventData
     */
    public function publishEventCreated(int $eventId, array $eventData): void
    {
        $this->publish($eventId, [
            'type' => 'event.created',
            'event' => $eventData,
        ]);
    }

    /**
     * @param array<string, mixed> $eventData
     */
    public function publishEventUpdated(int $eventId, array $eventData): void
    {
        $this->publish($eventId, [
            'type' => 'event.updated',
            'event' => $eventData,
        ]);
    }

    public function publishEventDeleted(int $eventId): void
    {
        $this->publish($eventId, [
            'type' => 'event.deleted',
            'eventId' => $eventId,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function publishParticipantChanged(int $eventId, array $data): void
    {
        $this->publish($eventId, [
            'type' => 'participant.changed',
            'eventId' => $eventId,
            'data' => $data,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function publish(int $eventId, array $payload): void
    {
        $prefix = $this->getTopicPrefix();
        $topic = "{$prefix}/calendars/events/{$eventId}";
        $globalTopic = "{$prefix}/calendars/events";
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        $update = new Update(
            topics: [$topic, $globalTopic],
            data: $json,
        );

        $this->hub->publish($update);
    }

    private function getTopicPrefix(): string
    {
        $tenant = $this->tenantContext->getTenant();
        if ($tenant !== null) {
            return '/tenants/' . $tenant->slug();
        }

        return '';
    }
}
