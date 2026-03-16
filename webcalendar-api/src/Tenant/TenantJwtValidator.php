<?php

declare(strict_types=1);

namespace App\Tenant;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Validates that the JWT tenant claim matches the resolved tenant context.
 *
 * Runs at lower priority than TenantResolverListener (after tenant is resolved)
 * and after authentication (which sets the _jwt_tenant request attribute).
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 10)]
final readonly class TenantJwtValidator
{
    public function __construct(
        private TenantContext $tenantContext,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if ($event->getRequestType() !== HttpKernelInterface::MAIN_REQUEST) {
            return;
        }

        $tenant = $this->tenantContext->getTenant();
        if ($tenant === null) {
            return;
        }

        $jwtTenant = $event->getRequest()->attributes->get('_jwt_tenant');
        if (!\is_string($jwtTenant) || $jwtTenant === '') {
            return;
        }

        if ($jwtTenant !== $tenant->slug()) {
            $event->setResponse(new JsonResponse(
                ['data' => null, 'meta' => null, 'error' => [
                    'code' => 403,
                    'message' => 'JWT tenant claim does not match request tenant',
                    'details' => [],
                ]],
                403,
            ));
        }
    }
}
