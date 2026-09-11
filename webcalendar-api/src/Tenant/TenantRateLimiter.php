<?php

declare(strict_types=1);

namespace App\Tenant;

use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Per-tenant rate limiting by plan. The counting backend is swapped
 * via {@see TenantRateLimitStorage} (file for single-node dev, Redis
 * for multi-node prod — see PBP-S11).
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 150)]
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -10)]
final class TenantRateLimiter
{
    private ?int $limit = null;
    private ?int $remaining = null;
    private ?int $resetAt = null;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantRateLimitStorage $storage,
        private readonly ClockInterface $clock = new NativeClock(),
    ) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        if ($event->getRequestType() !== HttpKernelInterface::MAIN_REQUEST) {
            return;
        }

        // These three describe the request being handled now. A container that
        // serves more than one request would otherwise keep the last figures
        // that were worked out, and onKernelResponse would put another tenant's
        // plan and remaining quota on a response that is not theirs -- most
        // visibly on a base-domain request, which resolves no tenant and
        // returns just below.
        $this->limit = null;
        $this->remaining = null;
        $this->resetAt = null;

        $tenant = $this->tenantContext->getTenant();
        if ($tenant === null) {
            return;
        }

        $slug = $tenant->slug();
        $this->limit = self::limitFor($tenant->plan());

        $window = $this->currentWindow();
        $this->resetAt = $window + 60;

        $count = $this->storage->incrementAndCount($slug, $window);
        $this->remaining = max(0, $this->limit - $count);

        if ($count > $this->limit) {
            $response = new JsonResponse(
                ['data' => null, 'meta' => null, 'error' => ['code' => 429, 'message' => 'Rate limit exceeded', 'details' => []]],
                429,
            );
            $response->headers->set('X-RateLimit-Limit', (string) $this->limit);
            $response->headers->set('X-RateLimit-Remaining', '0');
            $response->headers->set('X-RateLimit-Reset', (string) $this->resetAt);
            $response->headers->set('Retry-After', '60');

            $event->setResponse($response);
        }
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if ($event->getRequestType() !== HttpKernelInterface::MAIN_REQUEST) {
            return;
        }

        if ($this->limit === null) {
            return;
        }

        $response = $event->getResponse();
        $response->headers->set('X-RateLimit-Limit', (string) $this->limit);
        $response->headers->set('X-RateLimit-Remaining', (string) ($this->remaining ?? 0));
        $response->headers->set('X-RateLimit-Reset', (string) ($this->resetAt ?? 0));
    }

    private static function limitFor(TenantPlan $plan): int
    {
        return match ($plan) {
            TenantPlan::Free => 100,
            TenantPlan::Pro => 1000,
            TenantPlan::Enterprise => 5000,
        };
    }

    private function currentWindow(): int
    {
        return intdiv($this->clock->now()->getTimestamp(), 60) * 60;
    }
}
