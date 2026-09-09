<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\ErrorMetricsService;
use App\Service\ReadinessProbe;
use App\Tenant\TenantContext;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Split liveness (`/health`) from readiness (`/ready`) — PBP-S13.
 *
 * - `/health` returns 200 as long as the PHP process is alive. No I/O,
 *   no DB ping. This is what a K8s liveness probe should hit: a failure
 *   here means restart the pod.
 * - `/ready` runs a timeout-bounded DB ping plus component summary.
 *   This is what a K8s readiness probe should hit: a failure here means
 *   stop routing traffic, but don't restart.
 */
final class HealthController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ReadinessProbe $probe,
        private readonly string $appMode,
        private readonly ?ErrorMetricsService $errorMetrics = null,
        // Defaulted so the container autowires the real logger while code
        // that constructs this directly keeps working.
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ClockInterface $clock = new NativeClock(),
    ) {}

    #[Route('/api/v2/health', name: 'api_health', methods: ['GET'])]
    public function liveness(): JsonResponse
    {
        return new JsonResponse([
            'status' => 'ok',
            'timestamp' => $this->clock->now()->format('c'),
            'mode' => $this->appMode,
        ]);
    }

    #[Route('/api/v2/ready', name: 'api_ready', methods: ['GET'])]
    public function readiness(): JsonResponse
    {
        $dbStatus = $this->probe->pingDatabase();

        $components = [
            'database' => $dbStatus->ok ? 'ok' : 'error',
        ];
        if ($dbStatus->latencyMs !== null) {
            $components['database_latency_ms'] = $dbStatus->latencyMs;
        }

        $components['mercure'] = getenv('MERCURE_URL') !== false ? 'configured' : 'not configured';

        $redisUrl = getenv('REDIS_URL');
        if (\is_string($redisUrl) && $redisUrl !== '') {
            $components['redis'] = 'configured';
        }

        $recentErrors = 0;
        try {
            $recentErrors = $this->errorMetrics?->getRecentErrorCount() ?? 0;
        } catch (\Throwable $e) {
            $this->logger->debug('errorMetrics->getRecentErrorCount() failed', ['exception' => $e->getMessage()]);
        }

        $response = [
            'status' => $dbStatus->ok ? 'ok' : 'degraded',
            'timestamp' => $this->clock->now()->format('c'),
            'mode' => $this->appMode,
            'components' => $components,
            'recent_errors' => $recentErrors,
        ];

        $tenant = $this->tenantContext->getTenant();
        if ($tenant !== null) {
            $response['tenant'] = $tenant->slug();
        }

        return new JsonResponse($response, $dbStatus->ok ? 200 : 503);
    }
}
