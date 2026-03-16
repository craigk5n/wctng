<?php

declare(strict_types=1);

namespace App\Tenant;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Resolves the current tenant from the request subdomain.
 *
 * Extracts the slug from `{slug}.{base_domain}` in the Host header,
 * looks it up in the tenant registry, and sets the TenantContext.
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

        $host = $event->getRequest()->getHost();

        // Skip if the host is the base domain itself (no subdomain)
        if ($host === $this->baseDomain) {
            return;
        }

        // Extract subdomain: {slug}.{baseDomain}
        $suffix = '.' . $this->baseDomain;
        if (!str_ends_with($host, $suffix)) {
            return;
        }

        $slug = substr($host, 0, -\strlen($suffix));
        if ($slug === '') {
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
}
