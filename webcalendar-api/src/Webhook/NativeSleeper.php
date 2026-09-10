<?php

declare(strict_types=1);

namespace App\Webhook;

final class NativeSleeper implements Sleeper
{
    #[\Override]
    public function sleep(int $seconds): void
    {
        \sleep($seconds);
    }
}
