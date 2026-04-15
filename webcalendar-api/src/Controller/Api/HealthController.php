<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\ErrorMetricsService;
use App\Tenant\TenantContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly \PDO $pdo,
        private readonly string $appMode,
        private readonly ?ErrorMetricsService $errorMetrics = null,
    ) {}

    #[Route('/api/v2/health', name: 'api_health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $components = [];

        // Database check — always hits the default DB, not tenant DBs.
        // A tenant DB outage should surface as a per-request failure
        // elsewhere, not as a global /health red flag.
        try {
            $this->pdo->query('SELECT 1');
            $components['database'] = 'ok';
        } catch (\Throwable) {
            $components['database'] = 'error';
        }

        // Mercure check (via env var existence)
        $components['mercure'] = getenv('MERCURE_URL') !== false ? 'configured' : 'not configured';

        // Redis check
        $redisUrl = getenv('REDIS_URL');
        if (\is_string($redisUrl) && $redisUrl !== '') {
            $components['redis'] = 'configured';
        }

        $allOk = $components['database'] === 'ok';

        // Error metrics
        $recentErrors = 0;
        try {
            $recentErrors = $this->errorMetrics?->getRecentErrorCount() ?? 0;
        } catch (\Throwable) {
        }

        $response = [
            'status' => $allOk ? 'ok' : 'degraded',
            'timestamp' => date('c'),
            'mode' => $this->appMode,
            'components' => $components,
            'recent_errors' => $recentErrors,
        ];

        $tenant = $this->tenantContext->getTenant();
        if ($tenant !== null) {
            $response['tenant'] = $tenant->slug();
        }

        return new JsonResponse($response, $allOk ? 200 : 503);
    }
}
