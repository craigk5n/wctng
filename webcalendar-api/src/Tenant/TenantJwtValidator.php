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
 * Runs after TenantResolverListener, which resolves the tenant, and after the
 * security firewall, which decodes the JWT and so sets the _jwt_tenant request
 * attribute this compares against.
 *
 * The priority has to stay below the firewall's, which is 8. At the 10 it was
 * registered with, this listener ran before the token had been decoded, found
 * no _jwt_tenant on the request, and returned without comparing anything --
 * the mismatch it exists to refuse could not be reached. Check the real order
 * with `bin/console debug:event-dispatcher kernel.request` rather than reading
 * it off the priorities: the firewall's is fixed by the framework.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 0)]
final readonly class TenantJwtValidator
{
    public function __construct(
        private TenantContext $tenantContext,
    ) {}

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
