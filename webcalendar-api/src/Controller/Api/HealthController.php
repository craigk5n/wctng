<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\CoreServiceFactory;
use App\Tenant\TenantContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly CoreServiceFactory $coreServiceFactory,
        private readonly string $appMode,
    ) {
    }

    #[Route('/api/v2/health', name: 'api_health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $components = [];

        // Database check
        try {
            $pdo = $this->coreServiceFactory->getPdo();
            $pdo->query('SELECT 1');
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

        $response = [
            'status' => $allOk ? 'ok' : 'degraded',
            'timestamp' => date('c'),
            'mode' => $this->appMode,
            'components' => $components,
        ];

        $tenant = $this->tenantContext->getTenant();
        if ($tenant !== null) {
            $response['tenant'] = $tenant->slug();
        }

        return new JsonResponse($response, $allOk ? 200 : 503);
    }
}
