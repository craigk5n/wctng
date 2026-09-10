<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use App\Auth\OidcConfigFetcher;

/** Returns a scripted body and records every URL it was asked for. */
final class FakeOidcConfigFetcher implements OidcConfigFetcher
{
    /** @var list<string> */
    public array $requested = [];

    public function __construct(
        private readonly ?string $body = null,
        private readonly ?string $refuse = null,
    ) {}

    #[\Override]
    public function fetch(string $url): ?string
    {
        $this->requested[] = $url;

        if ($this->refuse !== null) {
            throw new \InvalidArgumentException($this->refuse);
        }

        return $this->body;
    }
}
