<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Services;

use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Contracts\LeadOriginProvider;
use App\Modules\LeadsWorkspace\Data\LeadOrigin;

/**
 * The default {@see LeadOriginProvider}: no lead has an origin beyond its source.
 *
 * Bound by the Leads Workspace provider so the detail screen works on its own — a deployment
 * without Signals, and every lead that was simply discovered or imported, render the panel away
 * rather than needing a null check in the controller.
 */
final class NoLeadOrigin implements LeadOriginProvider
{
    public function originFor(Lead $lead): ?LeadOrigin
    {
        return null;
    }
}
