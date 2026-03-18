<?php

declare(strict_types=1);

namespace App\Subscription;

final readonly class CalendarSubscription
{
    public function __construct(
        private int $id,
        private string $userLogin,
        private string $url,
        private string $name,
        private string $color,
        private int $refreshInterval,
        private ?string $lastFetched,
        private ?string $etag,
    ) {
    }

    public function id(): int { return $this->id; }
    public function userLogin(): string { return $this->userLogin; }
    public function url(): string { return $this->url; }
    public function name(): string { return $this->name; }
    public function color(): string { return $this->color; }
    public function refreshInterval(): int { return $this->refreshInterval; }
    public function lastFetched(): ?string { return $this->lastFetched; }
    public function etag(): ?string { return $this->etag; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'user_login' => $this->userLogin,
            'url' => $this->url,
            'name' => $this->name,
            'color' => $this->color,
            'refresh_interval' => $this->refreshInterval,
            'last_fetched' => $this->lastFetched,
            'etag' => $this->etag,
        ];
    }
}
