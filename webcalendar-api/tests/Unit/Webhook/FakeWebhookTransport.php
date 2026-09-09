<?php

declare(strict_types=1);

namespace App\Tests\Unit\Webhook;

use App\Webhook\WebhookTransport;

/**
 * Returns scripted status codes so the retry loop can be driven without a
 * server, and records what it was asked to send.
 */
final class FakeWebhookTransport implements WebhookTransport
{
    /** @var list<array{url: string, payload: string, signature: string}> */
    public array $sent = [];

    /** @param list<int> $statusCodes one per call; the last repeats once exhausted */
    public function __construct(
        private readonly array $statusCodes = [200],
        private readonly ?string $blockedUrl = null,
    ) {}

    public function calls(): int
    {
        return \count($this->sent);
    }

    #[\Override]
    public function post(string $url, string $payload, string $signature): int
    {
        if ($this->blockedUrl !== null && $url === $this->blockedUrl) {
            throw new \InvalidArgumentException('Host "blocked.example.com" resolves to a private address (10.0.0.1).');
        }

        $index = \count($this->sent);
        $this->sent[] = ['url' => $url, 'payload' => $payload, 'signature' => $signature];

        return $this->statusCodes[$index] ?? $this->statusCodes[array_key_last($this->statusCodes)] ?? 0;
    }
}
