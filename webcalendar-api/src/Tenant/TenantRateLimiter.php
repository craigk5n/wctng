<?php

declare(strict_types=1);

namespace App\Tenant;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Per-tenant rate limiting based on plan.
 *
 * Uses a simple file-based counter per tenant per minute window.
 * In production, this should be backed by Redis.
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
        private readonly string $storageDir,
    ) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        if ($event->getRequestType() !== HttpKernelInterface::MAIN_REQUEST) {
            return;
        }

        $tenant = $this->tenantContext->getTenant();
        if ($tenant === null) {
            return; // Standalone mode — no rate limiting
        }

        $slug = $tenant->slug();
        $this->limit = match ($tenant->plan()) {
            TenantPlan::Free => 100,
            TenantPlan::Pro => 1000,
            TenantPlan::Enterprise => 5000,
        };

        $window = $this->getCurrentWindow();
        $this->resetAt = $window + 60;

        $count = $this->incrementCounter($slug, $window);
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

    private function getCurrentWindow(): int
    {
        return (int) (floor(time() / 60) * 60);
    }

    private function incrementCounter(string $slug, int $window): int
    {
        $dir = $this->storageDir . '/rate_limits';
        if (!is_dir($dir)) {
            @mkdir($dir, 0o777, true);
        }

        $file = $dir . '/' . $slug . '_' . $window . '.count';

        // Clean old windows
        $this->cleanOldWindows($dir, $slug, $window);

        $fp = fopen($file, 'c+');
        if ($fp === false) {
            return 1;
        }

        flock($fp, LOCK_EX);
        $content = fread($fp, 100);
        $current = $content !== false && $content !== '' ? (int) $content : 0;
        $current++;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, (string) $current);
        flock($fp, LOCK_UN);
        fclose($fp);

        return $current;
    }

    private function cleanOldWindows(string $dir, string $slug, int $currentWindow): void
    {
        $pattern = $dir . '/' . $slug . '_*.count';
        $files = glob($pattern);
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            $basename = basename($file, '.count');
            $parts = explode('_', $basename);
            $fileWindow = (int) end($parts);
            if ($fileWindow < $currentWindow) {
                @unlink($file);
            }
        }
    }
}
