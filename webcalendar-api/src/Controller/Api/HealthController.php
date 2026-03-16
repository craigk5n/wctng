<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Tenant\TenantContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly string $appMode,
    ) {
    }

    #[Route('/api/v2/health', name: 'api_health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $response = [
            'status' => 'ok',
            'timestamp' => date('c'),
            'mode' => $this->appMode,
        ];

        $tenant = $this->tenantContext->getTenant();
        if ($tenant !== null) {
            $response['tenant'] = $tenant->slug();
        }

        return new JsonResponse($response);
    }
}
