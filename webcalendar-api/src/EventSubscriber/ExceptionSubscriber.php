<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Response\ApiResponse;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use WebCalendar\Core\Domain\Exception\AuthorizationException;

/**
 * Converts all exceptions to the standard API error envelope.
 *
 * Catches exceptions thrown during request handling and returns
 * a consistent JSON error response: {data: null, meta: null, error: {code, message, details}}.
 */
final class ExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly string $environment,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 0],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        $pathInfo = $request->getPathInfo();

        // Only handle API routes — let Symfony handle non-API errors normally
        if (!str_starts_with($pathInfo, '/api/')) {
            return;
        }

        $exception = $event->getThrowable();

        if ($exception instanceof HttpExceptionInterface) {
            $statusCode = $exception->getStatusCode();
            $message = $exception->getMessage();
        } elseif ($exception instanceof AuthorizationException) {
            $statusCode = Response::HTTP_FORBIDDEN;
            $message = $exception->getMessage();
        } elseif ($exception instanceof \InvalidArgumentException) {
            $statusCode = Response::HTTP_BAD_REQUEST;
            $message = $exception->getMessage();
        } else {
            $statusCode = Response::HTTP_INTERNAL_SERVER_ERROR;
            $message = $this->environment === 'prod'
                ? 'Internal server error'
                : $exception->getMessage();
        }

        $response = ApiResponse::error($statusCode, $message);
        $event->setResponse($response);
    }
}
