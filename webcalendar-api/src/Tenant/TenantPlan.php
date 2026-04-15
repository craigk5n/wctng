<?php

declare(strict_types=1);

namespace App\Tenant;

/**
 * Tenant subscription plan (PBP-S10). The legacy code stored this as
 * a free-form string which made accidental typos ("pror", "enterprize")
 * silently survive writes; a backed enum catches them at the type
 * boundary and gives the IDE real autocomplete.
 */
enum TenantPlan: string
{
    case Free = 'free';
    case Pro = 'pro';
    case Enterprise = 'enterprise';
}
