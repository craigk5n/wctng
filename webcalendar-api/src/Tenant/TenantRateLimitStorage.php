<?php

declare(strict_types=1);

namespace App\Tenant;

/**
 * PBP-S11 boundary: atomically increment a per-tenant counter for a
 * minute window and return the post-increment count. Kept deliberately
 * narrow so every backend (file, Redis, in-memory for tests) implements
 * a single operation that is race-safe on its own terms — file uses
 * flock, Redis uses INCR+EXPIRE, memory uses the process's own single
 * thread.
 *
 * The caller owns window math (`intdiv($now, 60) * 60`, from its injected
 * clock) so storage backends never need a clock of their own.
 */
interface TenantRateLimitStorage
{
    /**
     * Increment the (tenant, window) counter by 1 and return the new count.
     *
     * Implementations MUST be atomic across concurrent callers that share
     * the same backend — file via LOCK_EX, Redis via INCR, etc. Implementations
     * SHOULD expire or garbage-collect counters older than the active window
     * so storage doesn't grow unboundedly.
     */
    public function incrementAndCount(string $slug, int $window): int;
}
