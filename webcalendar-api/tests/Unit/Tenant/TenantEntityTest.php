<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenant;

use App\Tenant\Tenant;
use App\Tenant\TenantPlan;
use App\Tenant\TenantStatus;
use PHPUnit\Framework\Attributes\DataProvider;
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

    // ------------------------------------------------- the length boundaries

    /** @return iterable<string, array{int, bool}> */
    public static function slugLengths(): iterable
    {
        // The existing cases reject 2 and 51 but never accept 3 or 50, so the
        // comparisons could both slip by one without a failure. The upper
        // bound is not arbitrary: the provisioner derives a database name of
        // "wc_tenant_" plus the slug, and MySQL caps an identifier at 64
        // characters, so 50 keeps every derived name comfortably inside it.
        yield 'two characters' => [2, false];
        yield 'three characters' => [3, true];
        yield 'fifty characters' => [50, true];
        yield 'fifty-one characters' => [51, false];
    }

    #[DataProvider('slugLengths')]
    public function testTheSlugLengthBoundsAreWhereTheyClaimToBe(int $length, bool $accepted): void
    {
        $slug = str_repeat('a', $length);

        if (!$accepted) {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('between 3 and 50 characters');
        }

        $tenant = new Tenant(
            id: 0,
            slug: $slug,
            name: 'Test',
            dbHost: '',
            dbName: '',
            dbUser: '',
            dbPassword: '',
            plan: TenantPlan::Free,
            status: TenantStatus::Pending,
        );

        self::assertSame($slug, $tenant->slug());
    }

    public function testTheLongestAllowedSlugStillMakesALegalMySqlIdentifier(): void
    {
        // Guards the relationship the bound exists for rather than the number:
        // if the cap ever rises past 54, the derived database name overflows
        // MySQL's 64-character limit and provisioning starts refusing tenants
        // that validated cleanly.
        $longest = str_repeat('a', 50);

        self::assertLessThanOrEqual(64, \strlen('wc_tenant_' . $longest));
        self::assertLessThanOrEqual(64, \strlen('wc_' . $longest));
    }

    // ------------------------------------------------------ mapping a row

    /** @return array<string, string|int|null> */
    private static function completeRow(): array
    {
        return [
            'id' => 42,
            'slug' => 'acme-corp',
            'name' => 'Acme Corporation',
            'db_host' => 'mysql.internal',
            'db_name' => 'wc_tenant_acme_corp',
            'db_user' => 'wc_acme_corp',
            'db_password' => 'encrypted-blob',
            'plan' => 'pro',
            'status' => 'active',
            'created_at' => '2026-03-15 10:30:00',
            'updated_at' => '2026-04-01 09:00:00',
        ];
    }

    public function testFromRowMapsEveryColumnItReads(): void
    {
        // fromRow() was only ever exercised for its two rejections -- a bad
        // plan and a bad status -- so nothing checked that a good row maps at
        // all. Every field here is read back out of the registry on every
        // tenant-scoped request.
        $tenant = Tenant::fromRow(self::completeRow());

        self::assertSame(42, $tenant->id());
        self::assertSame('acme-corp', $tenant->slug());
        self::assertSame('Acme Corporation', $tenant->name());
        self::assertSame('mysql.internal', $tenant->dbHost());
        self::assertSame('wc_tenant_acme_corp', $tenant->dbName());
        self::assertSame('wc_acme_corp', $tenant->dbUser());
        self::assertSame('encrypted-blob', $tenant->dbPassword());
        self::assertSame(TenantPlan::Pro, $tenant->plan());
        self::assertSame(TenantStatus::Active, $tenant->status());
        self::assertSame('2026-03-15 10:30:00', $tenant->createdAt()?->format('Y-m-d H:i:s'));
        self::assertSame('2026-04-01 09:00:00', $tenant->updatedAt()?->format('Y-m-d H:i:s'));
    }

    public function testFromRowReadsTheIdAsAnIntOnAStringifyingDriver(): void
    {
        // MySQL's PDO returns every column as a string, and the id is compared
        // with === and used as an int by everything downstream.
        $row = self::completeRow();
        $row['id'] = '42';

        self::assertSame(42, Tenant::fromRow($row)->id());
    }

    public function testFromRowWithNoTimestampsLeavesThemUnset(): void
    {
        // A row from before those columns existed, or a partial select. The
        // guards return null rather than handing null to DateTimeImmutable.
        $row = self::completeRow();
        unset($row['created_at'], $row['updated_at']);

        $tenant = Tenant::fromRow($row);

        self::assertNull($tenant->createdAt());
        self::assertNull($tenant->updatedAt());
    }

    public function testFromRowWithNoIdTreatsItAsUnsaved(): void
    {
        // Zero is what the repository reads as "insert this", so a row with no
        // id must map to zero rather than to anything that would be taken for
        // an existing row.
        $row = self::completeRow();
        unset($row['id']);

        self::assertSame(0, Tenant::fromRow($row)->id());
    }
}
