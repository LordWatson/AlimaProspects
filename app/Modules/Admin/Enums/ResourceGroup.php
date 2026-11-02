<?php

declare(strict_types=1);

namespace App\Modules\Admin\Enums;

/**
 * How the registry's resources are grouped in the admin nav (Phase 19a).
 *
 * Grouped by the operator's question, not by our module boundaries — somebody chasing a stuck
 * discovery run does not know or care that leads and runs live in Lead Sourcing while notes live
 * in Leads Workspace. Where the two orders disagree, the operator's wins.
 */
enum ResourceGroup: string
{
    case Tenants = 'tenants';
    case Prospecting = 'prospecting';
    case Workspace = 'workspace';
    case Signals = 'signals';
    case Outreach = 'outreach';
    case Engagement = 'engagement';
    case Intelligence = 'intelligence';
    case Compliance = 'compliance';
    case Billing = 'billing';
    case Platform = 'platform';

    public function label(): string
    {
        return match ($this) {
            self::Tenants => 'Tenants & people',
            self::Prospecting => 'Prospecting',
            self::Workspace => 'Workspace',
            self::Signals => 'Signals',
            self::Outreach => 'Outreach',
            self::Engagement => 'Engagement',
            self::Intelligence => 'Intelligence',
            self::Compliance => 'Compliance',
            self::Billing => 'Billing',
            self::Platform => 'Platform',
        };
    }

    /**
     * Nav order. Explicit rather than declaration order, so adding a resource never reshuffles the
     * sidebar an operator has learned the shape of.
     */
    public function position(): int
    {
        return match ($this) {
            self::Tenants => 1,
            self::Prospecting => 2,
            self::Workspace => 3,
            self::Signals => 4,
            self::Outreach => 5,
            self::Engagement => 6,
            self::Intelligence => 7,
            self::Compliance => 8,
            self::Billing => 9,
            self::Platform => 10,
        };
    }
}
