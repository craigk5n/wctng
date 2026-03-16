<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use App\Tenant\TenantContext;
use App\Tenant\TenantExportService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class TenantExportController
{
    public function __construct(
        private readonly TenantExportService $exportService,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('/api/v2/tenant/export', name: 'api_tenant_export', methods: ['GET'])]
    public function export(#[CurrentUser] ?WebCalendarUser $user): Response
    {
        if ($user === null) {
            return ApiResponse::error(401, 'Authentication required');
        }

        $tenant = $this->tenantContext->getTenant();
        if ($tenant === null) {
            return ApiResponse::error(400, 'Tenant export is only available in multi-tenant mode');
        }

        try {
            $zipContent = $this->exportService->export($tenant);

            $response = new Response($zipContent);
            $response->headers->set('Content-Type', 'application/zip');
            $response->headers->set('Content-Disposition', "attachment; filename=\"{$tenant->slug()}-export.zip\"");

            return $response;
        } catch (\Throwable $e) {
            return ApiResponse::error(500, 'Export failed: ' . $e->getMessage());
        }
    }
}
