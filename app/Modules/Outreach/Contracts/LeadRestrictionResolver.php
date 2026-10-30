<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Contracts;

use App\Modules\Core\Enums\OutreachRestriction;
use App\Modules\Core\Models\Team;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Outreach\Services\OutreachGuard;
use App\Modules\Outreach\Services\RecordedLeadRestriction;

/**
 * What limits how this lead may be approached (ADR 0007, docs/compliance.md §2).
 *
 * {@see OutreachGuard} asks this rather than reading `leads.raw.outreach_restriction` itself,
 * because *which* restriction applies is a compliance judgement and Outreach is the enforcement
 * point, not the decider. The seam matters: the recorded flag is only ever set by the module that
 * created the lead, so a lead sourced from a UAE company register by Lead Sourcing carries none,
 * and a gate that read only the flag would wave it through (the exact hole Phase 15 closes).
 *
 * Two bindings:
 *
 * - {@see RecordedLeadRestriction} — the flag on the lead, nothing more. Outreach's own default, so
 *   the module stands up without Compliance.
 * - `Compliance\Services\MarketConsentGate` — the market-aware gate, bound by the Compliance
 *   module provider (Phase 15). It adds the prospect's own jurisdiction and the team's market.
 *
 * **A restriction may be added to a lead but never dropped from one** (docs/compliance.md §5a).
 * Every implementation is held to that: whatever is recorded on the lead stands, and a resolver may
 * only make the answer stricter.
 */
interface LeadRestrictionResolver
{
    /**
     * The restriction in force for this lead, or null when it carries none beyond the team's own
     * lawful basis. Null is never "cleared to cold-email" — that is the LIA's question.
     */
    public function restrictionFor(Team $team, Lead $lead): ?OutreachRestriction;
}
