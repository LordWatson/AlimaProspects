<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Services;

use App\Modules\Core\Enums\OutreachRestriction;
use App\Modules\Core\Models\Team;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Outreach\Contracts\LeadRestrictionResolver;

/**
 * Outreach's own default resolver: the restriction **recorded on the lead** and nothing else — what
 * Phase 11 enforced before Compliance existed.
 *
 * It stays as the default binding so Outreach is complete on its own, and because it is the floor
 * every other resolver builds on: Compliance's market-aware gate starts from this answer and may
 * only make it stricter (docs/compliance.md §5a — a restriction may be added to a lead but never
 * dropped from one).
 */
final class RecordedLeadRestriction implements LeadRestrictionResolver
{
    public function restrictionFor(Team $team, Lead $lead): ?OutreachRestriction
    {
        return OutreachRestriction::fromRaw($lead->raw);
    }
}
