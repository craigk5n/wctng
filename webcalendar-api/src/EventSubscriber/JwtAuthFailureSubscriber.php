<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Response\ApiResponse;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Converts security exceptions on API routes to JSON error envelopes.
 *
 * This runs before the ExceptionSubscriber to catch authentication/authorization
 * errors specifically and return proper 401/403 JSON responses.
 */
final class JwtAuthFailureSubscriber implements EventSubscriberInterface
{
    /**
     * @return array<string, array{0: string, 1: int}>
     */
    #[\Override]
    public static function getSubscribedEvents(): array
    {
        // High priority to run before the generic ExceptionSubscriber
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 10],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $exception = $event->getThrowable();

        if ($exception instanceof AuthenticationException) {
            $event->setResponse(ApiResponse::error(
                Response::HTTP_UNAUTHORIZED,
                'Authentication required',
            ));
            return;
        }

        if ($exception instanceof AccessDeniedException) {
            $event->setResponse(ApiResponse::error(
                Response::HTTP_FORBIDDEN,
                'Access denied',
            ));
        }
    }
}
