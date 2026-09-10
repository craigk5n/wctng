<?php

declare(strict_types=1);

namespace App\Tests\Unit\Webhook;

use App\Webhook\NativeSleeper;
use PHPUnit\Framework\TestCase;

/**
 * The one place the backoff is not faked.
 *
 * Everything else about the retry schedule is asserted against FakeSleeper,
 * which proves the dispatcher asks for the right waits but says nothing about
 * whether the production sleeper honours them. A no-op here would let a
 * failing endpoint be hit three times in a row with no pause at all.
 */
final class NativeSleeperTest extends TestCase
{
    public function testItActuallyWaits(): void
    {
        $before = microtime(true);

        (new NativeSleeper())->sleep(1);

        self::assertGreaterThanOrEqual(1.0, microtime(true) - $before);
    }
}
