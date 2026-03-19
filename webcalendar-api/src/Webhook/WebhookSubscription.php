<?php

declare(strict_types=1);

namespace App\Webhook;

/**
 * Entity representing a webhook subscription.
 */
final readonly class WebhookSubscription
{
    public function __construct(
        private int $id,
        private string $url,
        private string $events, // Comma-separated: event.created,event.updated,...
        private string $secret,
        private bool $enabled = true,
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }
    public function url(): string
    {
        return $this->url;
    }
    public function events(): string
    {
        return $this->events;
    }
    public function secret(): string
    {
        return $this->secret;
    }
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @return list<string>
     */
    public function eventList(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->events))));
    }

    public function subscribesTo(string $event): bool
    {
        return \in_array($event, $this->eventList(), true) || $this->events === '*';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'events' => $this->events,
            'enabled' => $this->enabled,
        ];
    }
}
