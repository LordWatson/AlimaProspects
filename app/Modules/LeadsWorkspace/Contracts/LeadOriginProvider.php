<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Contracts;

use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Data\LeadOrigin;
use App\Modules\LeadsWorkspace\Services\NoLeadOrigin;

/**
 * Resolves the richer story behind a lead for the detail screen's origin panel (S4).
 *
 * This exists to keep one dependency pointing one way. Signals creates leads, so Signals already
 * knows about Lead Sourcing and the workspace; the workspace must not learn about Signals in
 * return just to render a panel. So the workspace asks for an origin through this contract and the
 * default binding ({@see NoLeadOrigin}) answers "none" — Signals' provider replaces it, and a
 * later module with its own kind of origin does the same with no change to the screen or the
 * controller.
 */
interface LeadOriginProvider
{
    /**
     * The lead's origin, or null when it has none beyond its `source` (the ordinary case).
     *
     * Called once per detail-screen render, so it must not be expensive: at most one indexed query.
     * Must be called inside the lead's team context.
     */
    public function originFor(Lead $lead): ?LeadOrigin;
}
