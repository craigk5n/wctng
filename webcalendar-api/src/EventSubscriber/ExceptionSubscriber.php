<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Response\ApiResponse;
use App\Service\ErrorMetricsService;
use Psr\Log\LoggerInterface;
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
 * Logs 4xx/5xx with structured context and tracks 5xx errors in metrics.
 */
final class ExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly string $environment,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?ErrorMetricsService $errorMetrics = null,
    ) {}

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

        // Log with structured context
        $this->logError($event, $statusCode, $exception);

        // Track 5xx errors in metrics
        if ($statusCode >= 500) {
            try {
                $this->errorMetrics?->recordError();
            } catch (\Throwable) {
            }
        }
    }

    private function logError(ExceptionEvent $event, int $statusCode, \Throwable $exception): void
    {
        if ($this->logger === null) {
            return;
        }

        $request = $event->getRequest();
        $requestId = $request->attributes->getString('_request_id');

        // Calculate duration if request start time is available
        $startTime = $request->server->get('REQUEST_TIME_FLOAT');
        $durationMs = \is_float($startTime) ? round((microtime(true) - $startTime) * 1000, 1) : null;

        $context = [
            'request_id' => $requestId,
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'status' => $statusCode,
            'exception' => $exception::class,
        ];

        if ($durationMs !== null) {
            $context['duration_ms'] = $durationMs;
        }

        // Include user if available
        $user = $request->attributes->get('_security_token_user');
        if (\is_string($user)) {
            $context['user'] = $user;
        }

        if ($statusCode >= 500) {
            $this->logger->error($exception->getMessage(), $context);
        } else {
            $this->logger->warning($exception->getMessage(), $context);
        }
    }
}
