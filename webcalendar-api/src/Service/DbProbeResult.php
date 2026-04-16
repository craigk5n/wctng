<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Outcome of a single readiness DB ping (PBP-S13).
 */
final readonly class DbProbeResult
{
    public function __construct(
        public bool $ok,
        public ?int $latencyMs,
    ) {}
}
