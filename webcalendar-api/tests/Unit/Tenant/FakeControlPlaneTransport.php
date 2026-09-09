<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenant;

use App\Tenant\ControlPlaneTransport;

/**
 * Records what it was asked to send and returns a scripted status code.
 */
final class FakeControlPlaneTransport implements ControlPlaneTransport
{
    /** @var list<array{url: string, payload: string}> */
    public array $sent = [];

    public function __construct(
        private readonly int $statusCode = 200,
        private readonly ?\Throwable $failure = null,
    ) {}

    /** @return array<string, mixed> the decoded payload of the only call made */
    public function onlyPayload(): array
    {
        if (\count($this->sent) !== 1) {
            throw new \RuntimeException('expected exactly one send, got ' . \count($this->sent));
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($this->sent[0]['payload'], true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    #[\Override]
    public function post(string $url, string $payload): int
    {
        $this->sent[] = ['url' => $url, 'payload' => $payload];

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->statusCode;
    }
}
