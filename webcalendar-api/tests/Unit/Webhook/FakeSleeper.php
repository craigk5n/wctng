<?php

declare(strict_types=1);

namespace App\Tests\Unit\Webhook;

use App\Webhook\Sleeper;

/** Records what it was asked to wait for instead of waiting. */
final class FakeSleeper implements Sleeper
{
    /** @var list<int> */
    public array $slept = [];

    #[\Override]
    public function sleep(int $seconds): void
    {
        $this->slept[] = $seconds;
    }
}
