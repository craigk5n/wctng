<?php

declare(strict_types=1);

namespace App\Tenant;

use Predis\ClientInterface;

/**
 * Redis-backed counter. Chose Predis (pure PHP) over phpredis so the
 * API container doesn't need the PECL extension compiled — this ships
 * with the same runtime everywhere.
 *
 * Counter semantics:
 * - Key: `ratelimit:{slug}:{window_epoch_sec}`
 * - INCR returns the post-increment count atomically (no GET-then-SET race)
 * - First writer calls EXPIRE so the key self-destructs after 2 windows,
 *   which keeps Redis memory bounded without a cleanup job
 */
final readonly class RedisTenantRateLimitStorage implements TenantRateLimitStorage
{
    private const KEY_TTL_SECONDS = 120; // 2x the 60s window so a slow rollover doesn't prune live counters

    public function __construct(
        private ClientInterface $redis,
    ) {}

    #[\Override]
    public function incrementAndCount(string $slug, int $window): int
    {
        $key = $this->keyFor($slug, $window);

        /** @var int $count */
        $count = $this->redis->incr($key);

        if ($count === 1) {
            $this->redis->expire($key, self::KEY_TTL_SECONDS);
        }

        return $count;
    }

    private function keyFor(string $slug, int $window): string
    {
        return "ratelimit:{$slug}:{$window}";
    }
}
