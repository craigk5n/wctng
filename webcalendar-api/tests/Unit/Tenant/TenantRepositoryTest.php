<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenant;

use App\Tenant\Tenant;
use App\Tenant\TenantPlan;
use App\Tenant\TenantRepository;
use App\Tenant\TenantStatus;
use PHPUnit\Framework\TestCase;

final class TenantRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private TenantRepository $repo;

    #[\Override]
    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(TenantRepository::SCHEMA_SQL);
        $this->repo = new TenantRepository($this->pdo);
    }

    public function testSaveAndFindBySlug(): void
    {
        $tenant = new Tenant(
            id: 0,
            slug: 'acme',
            name: 'Acme Corp',
            dbHost: 'localhost',
            dbName: 'wc_acme',
            dbUser: 'wc_user',
            dbPassword: 'secret',
            plan: TenantPlan::Pro,
            status: TenantStatus::Active,
        );

        $id = $this->repo->save($tenant);
        $this->assertGreaterThan(0, $id);

        $found = $this->repo->findBySlug('acme');
        $this->assertNotNull($found);
        $this->assertSame('acme', $found->slug());
        $this->assertSame('Acme Corp', $found->name());
        $this->assertSame(TenantPlan::Pro, $found->plan());
    }

    public function testFindBySlugReturnsNullForMissing(): void
    {
        $this->assertNull($this->repo->findBySlug('nonexistent'));
    }

    public function testFindAllReturnsAllTenants(): void
    {
        $this->repo->save(new Tenant(0, 'alpha', 'Alpha', 'h', 'd', 'u', 'p', TenantPlan::Free, TenantStatus::Active));
        $this->repo->save(new Tenant(0, 'beta', 'Beta', 'h', 'd', 'u', 'p', TenantPlan::Pro, TenantStatus::Active));

        $all = $this->repo->findAll();
        $this->assertCount(2, $all);
    }

    public function testUpdateExistingTenant(): void
    {
        $id = $this->repo->save(new Tenant(0, 'gamma', 'Gamma', 'h', 'd', 'u', 'p', TenantPlan::Free, TenantStatus::Active));

        $updated = new Tenant($id, 'gamma', 'Gamma Updated', 'h2', 'd2', 'u2', 'p2', TenantPlan::Pro, TenantStatus::Suspended);
        $this->repo->save($updated);

        $found = $this->repo->findBySlug('gamma');
        $this->assertNotNull($found);
        $this->assertSame('Gamma Updated', $found->name());
        $this->assertSame(TenantPlan::Pro, $found->plan());
        $this->assertSame(TenantStatus::Suspended, $found->status());
    }

    public function testDelete(): void
    {
        $id = $this->repo->save(new Tenant(0, 'delta', 'Delta', 'h', 'd', 'u', 'p', TenantPlan::Free, TenantStatus::Active));
        $this->assertNotNull($this->repo->findBySlug('delta'));

        $this->repo->delete($id);
        $this->assertNull($this->repo->findBySlug('delta'));
    }
}
