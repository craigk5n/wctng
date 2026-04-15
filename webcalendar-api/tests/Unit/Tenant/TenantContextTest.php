<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenant;

use App\Tenant\Tenant;
use App\Tenant\TenantContext;
use App\Tenant\TenantPlan;
use App\Tenant\TenantStatus;
use PHPUnit\Framework\TestCase;

final class TenantContextTest extends TestCase
{
    public function testInitiallyHasNoTenant(): void
    {
        $ctx = new TenantContext();
        $this->assertNull($ctx->getTenant());
        $this->assertFalse($ctx->isMultiTenant());
    }

    public function testSetAndGetTenant(): void
    {
        $ctx = new TenantContext();
        $tenant = new Tenant(1, 'acme', 'Acme', 'h', 'd', 'u', 'p', TenantPlan::Pro, TenantStatus::Active);

        $ctx->setTenant($tenant);

        $this->assertSame($tenant, $ctx->getTenant());
        $this->assertTrue($ctx->isMultiTenant());
    }

    public function testResetClearsTenant(): void
    {
        $ctx = new TenantContext();
        $tenant = new Tenant(1, 'acme', 'Acme', 'h', 'd', 'u', 'p', TenantPlan::Pro, TenantStatus::Active);

        $ctx->setTenant($tenant);
        $this->assertTrue($ctx->isMultiTenant());

        $ctx->reset();
        $this->assertNull($ctx->getTenant());
        $this->assertFalse($ctx->isMultiTenant());
    }

    public function testGetTenantOrFailThrowsWhenEmpty(): void
    {
        $ctx = new TenantContext();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No tenant');
        $ctx->getTenantOrFail();
    }

    public function testGetTenantOrFailReturnsTenant(): void
    {
        $ctx = new TenantContext();
        $tenant = new Tenant(1, 'acme', 'Acme', 'h', 'd', 'u', 'p', TenantPlan::Pro, TenantStatus::Active);
        $ctx->setTenant($tenant);

        $this->assertSame($tenant, $ctx->getTenantOrFail());
    }
}
