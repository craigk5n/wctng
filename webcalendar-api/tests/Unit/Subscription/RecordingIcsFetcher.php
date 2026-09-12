<?php

declare(strict_types=1);

namespace App\Tests\Unit\Subscription;

use App\Subscription\IcsFetcher;

/**
 * IcsFetcher that answers from a script instead of the network, and keeps
 * what it was asked for so the conditional GET can be asserted.
 */
final class RecordingIcsFetcher implements IcsFetcher
{
    /** @var list<array{url: string, etag: string|null}> */
    public array $calls = [];

    /** @param array{body: string, etag: string|null}|null $response */
    public function __construct(
        private readonly ?array $response = null,
        private readonly ?\InvalidArgumentException $rejection = null,
    ) {}

    #[\Override]
    public function fetch(string $url, ?string $etag): ?array
    {
        $this->calls[] = ['url' => $url, 'etag' => $etag];

        if ($this->rejection !== null) {
            throw $this->rejection;
        }

        return $this->response;
    }
}
