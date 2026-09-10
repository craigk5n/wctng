<?php

declare(strict_types=1);

namespace App\Webhook;

/**
 * The wait between webhook delivery attempts.
 *
 * A seam over sleep(), for the same reason WebhookTransport is a seam over
 * curl: with the call welded in, the only way a test can afford to run the
 * retry loop is to configure the backoff to zero -- and once it is zero,
 * nothing about the schedule is observable any more. Whether the dispatcher
 * waits at all, waits the configured interval, or waits between the right
 * attempts then all look identical from outside.
 */
interface Sleeper
{
    public function sleep(int $seconds): void;
}
