<?php

declare(strict_types=1);

namespace App\Tenant;

use App\Response\ApiResponse;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Guards control plane routes, requiring a JWT with role=super_admin.
 *
 * Runs at priority 100 (after tenant resolution at 200, before controllers).
 * Skips the login endpoint which is public.
 * Blocks all control plane access in standalone mode.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 100)]
final readonly class ControlPlaneGuard
{
    public function __construct(
        private JWTEncoderInterface $jwtEncoder,
        private string $appMode,
    ) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        if ($event->getRequestType() !== HttpKernelInterface::MAIN_REQUEST) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();

        // Only guard /control/v1/* routes
        if (!str_starts_with($path, '/control/v1/')) {
            return;
        }

        // Block control plane in standalone mode
        if ($this->appMode === 'standalone') {
            $event->setResponse(ApiResponse::error(404, 'Control plane is not available in standalone mode'));
            return;
        }

        // Allow login endpoint without auth
        if ($path === '/control/v1/auth/login') {
            return;
        }

        $authHeader = $event->getRequest()->headers->get('Authorization', '');

        if (!str_starts_with($authHeader, 'Bearer ')) {
            $event->setResponse(ApiResponse::error(401, 'Authentication required'));
            return;
        }

        $token = substr($authHeader, 7);

        try {
            /** @var array<string, mixed> $payload */
            $payload = $this->jwtEncoder->decode($token);

            $role = isset($payload['role']) && \is_string($payload['role']) ? $payload['role'] : null;

            if ($role !== 'super_admin') {
                $event->setResponse(ApiResponse::error(403, 'Super admin access required'));
                return;
            }
        } catch (\Exception) {
            $event->setResponse(ApiResponse::error(401, 'Invalid or expired token'));
        }
    }
}
