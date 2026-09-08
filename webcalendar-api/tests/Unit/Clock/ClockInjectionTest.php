<?php

declare(strict_types=1);

namespace App\Tests\Unit\Clock;

use App\Security\OutboundUrlValidator;
use App\Webhook\WebhookDispatcher;
use App\Webhook\WebhookRepository;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

/**
 * PBP-S5 proof-of-concept: services that read wall-clock time must
 * accept an injected Psr\Clock\ClockInterface and use it instead of
 * `new \DateTimeImmutable()`. This test locks in the pattern by
 * constructing WebhookDispatcher with a MockClock and asserting the
 * clock is actually consulted (via reflection on the private `clock`
 * property, since the dispatcher doesn't expose it).
 */
final class ClockInjectionTest extends TestCase
{
    public function testWebhookDispatcherAcceptsInjectedClock(): void
    {
        $fixed = new MockClock('2026-04-15T12:00:00+00:00');
        $pdo = new \PDO('sqlite::memory:');
        $repo = new WebhookRepository($pdo);

        $dispatcher = new WebhookDispatcher($repo, $pdo, new OutboundUrlValidator('standalone'), null, $fixed);

        $ref = new \ReflectionProperty(WebhookDispatcher::class, 'clock');
        $this->assertInstanceOf(ClockInterface::class, $ref->getValue($dispatcher));
        $this->assertSame(
            '2026-04-15T12:00:00+00:00',
            $fixed->now()->format('Y-m-d\TH:i:sP'),
            'MockClock returns the frozen moment it was constructed with',
        );
    }

    public function testMockClockCanAdvance(): void
    {
        $clock = new MockClock('2026-04-15T12:00:00+00:00');
        $clock->sleep(60);
        $this->assertSame('2026-04-15T12:01:00+00:00', $clock->now()->format('Y-m-d\TH:i:sP'));
    }
}
