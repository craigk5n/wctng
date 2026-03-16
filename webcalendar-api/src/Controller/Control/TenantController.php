<?php

declare(strict_types=1);

namespace App\Controller\Control;

use App\Response\ApiResponse;
use App\Tenant\Tenant;
use App\Tenant\TenantDatabaseManager;
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

        return ApiResponse::noContent();
    }

    private function queryCount(\PDO $pdo, string $sql): int
    {
        $stmt = $pdo->query($sql);

        return $stmt !== false ? (int) $stmt->fetchColumn() : 0;
    }
}
