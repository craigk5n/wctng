<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenant;

use App\Tenant\Tenant;
use App\Tenant\TenantPlan;
use App\Tenant\TenantStatus;
use PHPUnit\Framework\TestCase;

final class TenantEntityTest extends TestCase
{
    public function testCreateValidTenant(): void
    {
        $tenant = new Tenant(
            id: 1,
            slug: 'acme',
            name: 'Acme Corp',
            dbHost: 'db-host-1',
            dbName: 'wc_tenant_acme',
            dbUser: 'wc_acme',
            dbPassword: 'encrypted_secret',
            plan: TenantPlan::Pro,
            status: TenantStatus::Active,
        );

        $this->assertSame(1, $tenant->id());
        $this->assertSame('acme', $tenant->slug());
        $this->assertSame('Acme Corp', $tenant->name());
        $this->assertSame('db-host-1', $tenant->dbHost());
        $this->assertSame('wc_tenant_acme', $tenant->dbName());
        $this->assertSame('wc_acme', $tenant->dbUser());
        $this->assertSame('encrypted_secret', $tenant->dbPassword());
        $this->assertSame(TenantPlan::Pro, $tenant->plan());
        $this->assertSame(TenantStatus::Active, $tenant->status());
        $this->assertTrue($tenant->isActive());
    }

    public function testSlugValidationRejectsUppercase(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('slug');

        new Tenant(id: 0, slug: 'ACME', name: 'Test', dbHost: '', dbName: '', dbUser: '', dbPassword: '', plan: TenantPlan::Free, status: TenantStatus::Pending);
    }

    public function testSlugValidationRejectsSpaces(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Tenant(id: 0, slug: 'my company', name: 'Test', dbHost: '', dbName: '', dbUser: '', dbPassword: '', plan: TenantPlan::Free, status: TenantStatus::Pending);
    }

    public function testSlugValidationRejectsTooShort(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Tenant(id: 0, slug: 'ab', name: 'Test', dbHost: '', dbName: '', dbUser: '', dbPassword: '', plan: TenantPlan::Free, status: TenantStatus::Pending);
    }

    public function testSlugValidationRejectsTooLong(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Tenant(id: 0, slug: str_repeat('a', 51), name: 'Test', dbHost: '', dbName: '', dbUser: '', dbPassword: '', plan: TenantPlan::Free, status: TenantStatus::Pending);
    }

    public function testSlugAllowsHyphens(): void
    {
        $tenant = new Tenant(id: 0, slug: 'my-company', name: 'Test', dbHost: '', dbName: '', dbUser: '', dbPassword: '', plan: TenantPlan::Free, status: TenantStatus::Pending);
        $this->assertSame('my-company', $tenant->slug());
    }

    public function testReservedSlugRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reserved');

        new Tenant(id: 0, slug: 'admin', name: 'Test', dbHost: '', dbName: '', dbUser: '', dbPassword: '', plan: TenantPlan::Free, status: TenantStatus::Pending);
    }

    public function testReservedSlugWwwRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Tenant(id: 0, slug: 'www', name: 'Test', dbHost: '', dbName: '', dbUser: '', dbPassword: '', plan: TenantPlan::Free, status: TenantStatus::Pending);
    }

    public function testReservedSlugApiRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Tenant(id: 0, slug: 'api', name: 'Test', dbHost: '', dbName: '', dbUser: '', dbPassword: '', plan: TenantPlan::Free, status: TenantStatus::Pending);
    }

    public function testStatusPending(): void
    {
        $tenant = new Tenant(id: 0, slug: 'test-co', name: 'Test', dbHost: '', dbName: '', dbUser: '', dbPassword: '', plan: TenantPlan::Free, status: TenantStatus::Pending);
        $this->assertFalse($tenant->isActive());
    }

    public function testStatusSuspended(): void
    {
        $tenant = new Tenant(id: 0, slug: 'test-co', name: 'Test', dbHost: '', dbName: '', dbUser: '', dbPassword: '', plan: TenantPlan::Free, status: TenantStatus::Suspended);
        $this->assertFalse($tenant->isActive());
    }

    public function testFromRowRejectsInvalidStatus(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('status');

        Tenant::fromRow([
            'id' => 1,
            'slug' => 'test-co',
            'name' => 'Test',
            'db_host' => '',
            'db_name' => '',
            'db_user' => '',
            'db_password' => '',
            'plan' => 'free',
            'status' => 'invalid',
        ]);
    }

    public function testFromRowRejectsInvalidPlan(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('plan');

        Tenant::fromRow([
            'id' => 1,
            'slug' => 'test-co',
            'name' => 'Test',
            'db_host' => '',
            'db_name' => '',
            'db_user' => '',
            'db_password' => '',
            'plan' => 'gold',
            'status' => 'active',
        ]);
    }
}
