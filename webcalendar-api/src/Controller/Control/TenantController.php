<?php

declare(strict_types=1);

namespace App\Controller\Control;

use App\Response\ApiResponse;
use App\Tenant\Tenant;
use App\Tenant\ControlPlaneWebhook;
use App\Tenant\TenantDatabaseManager;
use App\Tenant\TenantExportService;
use App\Tenant\TenantProvisioner;
use App\Tenant\TenantRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Control plane endpoints for tenant CRUD.
 * All routes guarded by ControlPlaneGuard (super_admin JWT required).
 */
final class TenantController
{
    public function __construct(
        private readonly TenantRepository $tenantRepository,
        private readonly TenantProvisioner $provisioner,
        private readonly TenantDatabaseManager $dbManager,
        private readonly ControlPlaneWebhook $webhook,
        private readonly TenantExportService $exportService,
    ) {
    }

    #[Route('/control/v1/tenants', name: 'control_tenants_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $tenants = $this->tenantRepository->findAll();

        $items = array_map(static fn (Tenant $t): array => [
            'slug' => $t->slug(),
            'name' => $t->name(),
            'plan' => $t->plan(),
            'status' => $t->status(),
            'created_at' => $t->createdAt()?->format('c'),
        ], $tenants);

        return ApiResponse::success(array_values($items));
    }

    #[Route('/control/v1/tenants', name: 'control_tenants_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $decoded = json_decode($request->getContent(), true);
        if (!\is_array($decoded)) {
            return ApiResponse::error(400, 'Invalid JSON body');
        }

        /** @var array<string, mixed> $data */
        $data = $decoded;

        $slug = isset($data['slug']) && \is_string($data['slug']) ? $data['slug'] : null;
        $name = isset($data['name']) && \is_string($data['name']) ? $data['name'] : null;
        $adminEmail = isset($data['admin_email']) && \is_string($data['admin_email']) ? $data['admin_email'] : 'admin@example.com';
        $plan = isset($data['plan']) && \is_string($data['plan']) ? $data['plan'] : 'free';

        if ($slug === null || $slug === '') {
            return ApiResponse::error(400, 'Missing required field: slug');
        }
        if ($name === null || $name === '') {
            return ApiResponse::error(400, 'Missing required field: name');
        }

        $result = $this->provisioner->provision($slug, $name, $adminEmail, $plan);

        if (!$result->success) {
            return ApiResponse::error(400, $result->error);
        }

        $this->webhook->tenantProvisioned($slug, $name);

        return ApiResponse::success([
            'slug' => $result->slug,
            'admin_email' => $result->adminEmail,
            'admin_password' => $result->adminPassword,
        ], null, Response::HTTP_CREATED);
    }

    #[Route('/control/v1/tenants/{slug}', name: 'control_tenants_get', methods: ['GET'])]
    public function get(string $slug): JsonResponse
    {
        $tenant = $this->tenantRepository->findBySlug($slug);

        if ($tenant === null) {
            return ApiResponse::error(404, 'Tenant not found');
        }

        $detail = [
            'slug' => $tenant->slug(),
            'name' => $tenant->name(),
            'plan' => $tenant->plan(),
            'status' => $tenant->status(),
            'db_host' => $tenant->dbHost(),
            'created_at' => $tenant->createdAt()?->format('c'),
            'updated_at' => $tenant->updatedAt()?->format('c'),
        ];

        // Try to get stats from tenant DB
        try {
            $pdo = $this->dbManager->getConnection($tenant);
            $userCount = $this->queryCount($pdo, 'SELECT COUNT(*) FROM webcal_user');
            $eventCount = $this->queryCount($pdo, 'SELECT COUNT(*) FROM webcal_entry');
            $detail['user_count'] = $userCount;
            $detail['event_count'] = $eventCount;
        } catch (\Throwable) {
            $detail['user_count'] = null;
            $detail['event_count'] = null;
        }

        return ApiResponse::success($detail);
    }

    #[Route('/control/v1/tenants/{slug}', name: 'control_tenants_update', methods: ['PUT'])]
    public function update(string $slug, Request $request): JsonResponse
    {
        $tenant = $this->tenantRepository->findBySlug($slug);

        if ($tenant === null) {
            return ApiResponse::error(404, 'Tenant not found');
        }

        $decoded = json_decode($request->getContent(), true);
        if (!\is_array($decoded)) {
            return ApiResponse::error(400, 'Invalid JSON body');
        }

        /** @var array<string, mixed> $data */
        $data = $decoded;

        $name = isset($data['name']) && \is_string($data['name']) ? $data['name'] : $tenant->name();
        $plan = isset($data['plan']) && \is_string($data['plan']) ? $data['plan'] : $tenant->plan();
        $status = isset($data['status']) && \is_string($data['status']) ? $data['status'] : $tenant->status();

        try {
            $updated = new Tenant(
                id: $tenant->id(),
                slug: $tenant->slug(),
                name: $name,
                dbHost: $tenant->dbHost(),
                dbName: $tenant->dbName(),
                dbUser: $tenant->dbUser(),
                dbPassword: $tenant->dbPassword(),
                plan: $plan,
                status: $status,
            );
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error(400, $e->getMessage());
        }

        $this->tenantRepository->save($updated);

        // Send webhook if status changed
        if ($status !== $tenant->status()) {
            if ($status === 'suspended') {
                $this->webhook->tenantSuspended($slug);
            } elseif ($status === 'active') {
                $this->webhook->tenantActivated($slug);
            }
        }

        return ApiResponse::success([
            'slug' => $updated->slug(),
            'name' => $updated->name(),
            'plan' => $updated->plan(),
            'status' => $updated->status(),
        ]);
    }

    #[Route('/control/v1/tenants/{slug}', name: 'control_tenants_delete', methods: ['DELETE'])]
    public function delete(string $slug, Request $request): Response
    {
        if ($request->query->getString('confirm') !== 'true') {
            return ApiResponse::error(400, 'Deletion requires ?confirm=true query parameter');
        }

        $tenant = $this->tenantRepository->findBySlug($slug);

        if ($tenant === null) {
            return ApiResponse::error(404, 'Tenant not found');
        }

        $this->dbManager->clearConnection($slug);
        $this->tenantRepository->delete($tenant->id());

        $this->webhook->tenantDeleted($slug);

        return ApiResponse::noContent();
    }

    #[Route('/control/v1/tenants/{slug}/stats', name: 'control_tenants_stats', methods: ['GET'])]
    public function stats(string $slug): JsonResponse
    {
        $tenant = $this->tenantRepository->findBySlug($slug);

        if ($tenant === null) {
            return ApiResponse::error(404, 'Tenant not found');
        }

        try {
            $pdo = $this->dbManager->getConnection($tenant);

            $userCount = $this->queryCount($pdo, 'SELECT COUNT(*) FROM webcal_user');
            $eventCount = $this->queryCount($pdo, 'SELECT COUNT(*) FROM webcal_entry');
            $taskCount = $this->queryCount($pdo, "SELECT COUNT(*) FROM webcal_entry WHERE cal_type IN ('T','N')");
            $lastActivity = $this->queryScalar($pdo, 'SELECT MAX(cal_mod_date) FROM webcal_entry');

            return ApiResponse::success([
                'slug' => $slug,
                'user_count' => $userCount,
                'event_count' => $eventCount,
                'task_count' => $taskCount,
                'last_activity' => $lastActivity,
            ]);
        } catch (\Throwable $e) {
            return ApiResponse::error(503, 'Unable to connect to tenant database: ' . $e->getMessage());
        }
    }

    #[Route('/control/v1/stats/summary', name: 'control_stats_summary', methods: ['GET'])]
    public function summary(): JsonResponse
    {
        $tenants = $this->tenantRepository->findAll();

        $totalTenants = \count($tenants);
        $activeTenants = 0;
        $totalUsers = 0;
        $totalEvents = 0;

        foreach ($tenants as $tenant) {
            if ($tenant->isActive()) {
                $activeTenants++;
            }
            try {
                $pdo = $this->dbManager->getConnection($tenant);
                $totalUsers += $this->queryCount($pdo, 'SELECT COUNT(*) FROM webcal_user');
                $totalEvents += $this->queryCount($pdo, 'SELECT COUNT(*) FROM webcal_entry');
            } catch (\Throwable) {
                // Skip unreachable tenant DBs
            }
        }

        return ApiResponse::success([
            'total_tenants' => $totalTenants,
            'active_tenants' => $activeTenants,
            'total_users' => $totalUsers,
            'total_events' => $totalEvents,
        ]);
    }

    #[Route('/control/v1/tenants/{slug}/export', name: 'control_tenants_export', methods: ['POST'])]
    public function exportTenant(string $slug): Response
    {
        $tenant = $this->tenantRepository->findBySlug($slug);

        if ($tenant === null) {
            return ApiResponse::error(404, 'Tenant not found');
        }

        try {
            $zipContent = $this->exportService->export($tenant);

            $response = new Response($zipContent);
            $response->headers->set('Content-Type', 'application/zip');
            $response->headers->set('Content-Disposition', "attachment; filename=\"{$slug}-export.zip\"");

            return $response;
        } catch (\Throwable $e) {
            return ApiResponse::error(500, 'Export failed: ' . $e->getMessage());
        }
    }

    private function queryCount(\PDO $pdo, string $sql): int
    {
        $stmt = $pdo->query($sql);

        return $stmt !== false ? (int) $stmt->fetchColumn() : 0;
    }

    private function queryScalar(\PDO $pdo, string $sql): ?string
    {
        $stmt = $pdo->query($sql);
        if ($stmt === false) {
            return null;
        }
        $val = $stmt->fetchColumn();

        return \is_string($val) || is_numeric($val) ? (string) $val : null;
    }
}
