<?php

declare(strict_types=1);

namespace App\Tenant;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Resolves the current tenant from the request.
 *
 * Resolution priority:
 * 1. Subdomain: `{slug}.{base_domain}` from Host header
 * 2. X-Tenant-Id header (fallback for API clients)
 * 3. No resolution (standalone or base domain requests)
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 200)]
final readonly class TenantResolverListener
{
    public function __construct(
        private TenantRepository $tenantRepository,
        private TenantContext $tenantContext,
        private string $baseDomain,
        private string $appMode,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        // Only process main requests
        if ($event->getRequestType() !== HttpKernelInterface::MAIN_REQUEST) {
            return;
        }

        // Skip in standalone mode
        if ($this->appMode === 'standalone') {
            return;
        }

        // Try subdomain resolution first
        $slug = $this->extractSubdomain($event->getRequest()->getHost());

        // Fallback to X-Tenant-Id header
        if ($slug === null) {
            $headerValue = $event->getRequest()->headers->get('X-Tenant-Id');
            if ($headerValue !== null && $headerValue !== '') {
                $slug = $headerValue;
            }
        }

        // No tenant identifier found — allow request through (base domain / control plane)
        if ($slug === null) {
            return;
        }

        $tenant = $this->tenantRepository->findBySlug($slug);

        if ($tenant === null) {
            $event->setResponse(new JsonResponse(
                ['data' => null, 'meta' => null, 'error' => ['code' => 404, 'message' => 'Tenant not found', 'details' => []]],
                404,
            ));

            return;
        }

        if (!$tenant->isActive()) {
            $event->setResponse(new JsonResponse(
                ['data' => null, 'meta' => null, 'error' => ['code' => 403, 'message' => 'Tenant is suspended', 'details' => []]],
                403,
            ));

            return;
        }

        $this->tenantContext->setTenant($tenant);
    }

    private function extractSubdomain(string $host): ?string
    {
        // Skip if the host is the base domain itself
        if ($host === $this->baseDomain) {
            return null;
        }

        $suffix = '.' . $this->baseDomain;
        if (!str_ends_with($host, $suffix)) {
            return null;
        }

        $slug = substr($host, 0, -\strlen($suffix));

        return $slug !== '' ? $slug : null;
    }
}
