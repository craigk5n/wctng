<?php

declare(strict_types=1);

namespace App\Tenant;

/**
 * Tenant lifecycle state (PBP-S10). Replaces the previous
 * `$status: string` with `VALID_STATUSES = ['active', 'suspended',
 * 'pending']` validation. Schema columns stay `VARCHAR`; the enum
 * lives at the domain boundary, with `Tenant::fromRow()` handling
 * the string → enum conversion on read.
 */
enum TenantStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Pending = 'pending';
}
