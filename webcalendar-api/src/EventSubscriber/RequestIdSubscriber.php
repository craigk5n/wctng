<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Tenant\TenantContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Adds a unique request ID to every request and includes it in logs and response headers.
 * Also adds tenant slug to log context for multi-tenant debugging.
 */
final class RequestIdSubscriber implements EventSubscriberInterface
{
    private ?string $requestId = null;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 250],
            KernelEvents::RESPONSE => ['onResponse', -100],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // Use provided X-Request-Id or generate one
        $this->requestId = $request->headers->get('X-Request-Id') ?? bin2hex(random_bytes(8));
        $request->attributes->set('_request_id', $this->requestId);

        // Log context
        $context = [
            'request_id' => $this->requestId,
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
        ];

        $tenant = $this->tenantContext->getTenant();
        if ($tenant !== null) {
            $context['tenant'] = $tenant->slug();
        }

        $this->logger->info('Request started', $context);
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || $this->requestId === null) {
            return;
        }

        $event->getResponse()->headers->set('X-Request-Id', $this->requestId);
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }
}
