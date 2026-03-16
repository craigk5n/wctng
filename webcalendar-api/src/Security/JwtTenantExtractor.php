<?php

declare(strict_types=1);

namespace App\Security;

use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTDecodedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Extracts the `tenant` claim from decoded JWT tokens and sets it
 * as a request attribute for the TenantJwtValidator to check.
 */
#[AsEventListener(event: 'lexik_jwt_authentication.on_jwt_decoded')]
final readonly class JwtTenantExtractor
{
    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    public function __invoke(JWTDecodedEvent $event): void
    {
        /** @var array<string, mixed> $payload */
        $payload = $event->getPayload();

        $tenant = isset($payload['tenant']) && \is_string($payload['tenant']) ? $payload['tenant'] : null;

        if ($tenant !== null && $tenant !== '') {
            $request = $this->requestStack->getCurrentRequest();
            $request?->attributes->set('_jwt_tenant', $tenant);
        }
    }
}
