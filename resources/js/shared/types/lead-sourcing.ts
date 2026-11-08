// Lead Sourcing (discovery runs + raw leads) shared types (Phase 7).

import type { LeadContact } from './enrichment';
import type { StageOutcome } from './leads-workspace';
import type { LeadScoreSummary } from './scoring';

/**
 * Every value `leads.source` can hold. The last three aren't providers — an import is the team's
 * own list, a manual lead one company a member typed in, a signal an event they chose to act on —
 * so none appears in the discovery picker, but all are filterable and all can label a lead
 * (mirrors `LeadSource::cases()`).
 */
export type LeadSource =
    | 'companies_house'
    | 'google_places'
    | 'web_search'
    | 'ai'
    | 'import'
    | 'manual'
    | 'signal';

/** A discovery provider: the subset of `LeadSource` that can actually be run. */
export type RunnableLeadSource =
    | 'companies_house'
    | 'google_places'
    | 'web_search'
    | 'ai';

/** A legal limit on how a lead may be approached (ADR 0007), when one applies. */
export type OutreachRestriction = {
    value: string;
    /** Translation key, not a finished string. */
    label: string;
    /** Translation key, not a finished string. */
    guidance: string;
    allows_direct_marketing: boolean;
};

export type EnrichmentStatus = 'pending' | 'enriching' | 'enriched' | 'failed';

export type DiscoveryRunStatus = 'pending' | 'running' | 'completed' | 'failed';

/** What sort of fact an item of evidence is (PL1); mirrors `EvidenceKind::cases()`. */
export type EvidenceKind =
    | 'signal'
    | 'firmographic'
    | 'web_research'
    | 'ai_inference'
    | 'register_record';

/** A member's verdict on an item of evidence (PL1); mirrors `EvidenceVerdict::cases()`. */
export type EvidenceVerdict = 'wrong' | 'outdated' | 'irrelevant' | 'useful';

/** How much a lead's evidence is worth, banded for display (PL1). Null means not judged yet. */
export type ConfidenceBand = 'high' | 'medium' | 'low';

/**
 * One dated, sourced reason a lead is worth contacting (PL1).
 *
 * `source` is a free string rather than a union: an evidence source is **data** here (ADR 0018
 * §1), so a later phase adds one without this type having to know. `source_label` is what to show.
 *
 * The provider's raw payload is deliberately absent — it is kept server-side for audit and can
 * name a person.
 */
export type LeadEvidence = {
    id: number;
    kind: EvidenceKind;
    /** Translation key, not a finished string. */
    kind_label: string;
    source: string;
    /** The outlet or register, as it should be shown. Never a translation key. */
    source_label: string;
    title: string;
    summary: string | null;
    source_url: string | null;
    /** When the event happened. Null where nobody stated a date. */
    observed_at: string | null;
    /** When we found it. The gap between the two is the honest answer to "is this fresh?". */
    captured_at: string;
    /** The source's weight when it was captured, 0–100. */
    reliability: number;
    /** What it is worth **today** — reliability thinned by age, and 0 once disputed. */
    weight: number;
    is_disputed: boolean;
    /** Only a trigger event gives a lead a timing score. */
    is_timing: boolean;
    signal_id: number | null;
    /** The viewer's own verdict, when the server loaded it. */
    my_verdict?: EvidenceVerdict | null;
};

/** A raw, company-level lead as discovered from a provider (pre-enrichment). */
/**
 * A prospect's subscriber type under a direct-marketing regime, as the server flattens it (PL5).
 *
 * `value` is `corporate`, `individual` or `unknown` today. It is deliberately a plain string: the
 * vocabulary belongs to a regime rather than to the product, and a client that switched on it would
 * have to be redeployed every time a market was added.
 */
export type SubscriberType = {
    value: string;
    /** Translation key, not a finished string. */
    label: string;
    /** Translation key. */
    guidance: string;
    /** Whether unsolicited marketing to this subscriber needs their prior consent. */
    needs_consent: boolean;
};

export type Lead = {
    id: number;
    source: LeadSource;
    source_label: string;
    status: string;
    status_label: string;
    enrichment_status: EnrichmentStatus;
    enrichment_status_label: string;
    company_name: string;
    company_number: string | null;
    company_status: string | null;
    company_type: string | null;
    industry: string | null;
    employee_range: string | null;
    technologies: string[];
    incorporated_on: string | null;
    website: string | null;
    locality: string | null;
    region: string | null;
    postal_code: string | null;
    country: string | null;
    /** The places the company says it operates in — not a postal address (Q1). */
    locations: string[];
    /**
     * How ready the lead is to be CONTACTED, 0–100 (Q4) — a measure of what we hold, not a verdict
     * about the prospect, and not a second fit score. Null means not computed yet, never zero.
     */
    readiness: number | null;
    /**
     * --- Why now (PL1) ---
     * How much the lead's evidence is worth, 0–100. **Null means nothing has been judged yet**,
     * which is not the same as zero — a screen must say "no reasons yet", never "confidence 0".
     */
    confidence: number | null;
    confidence_band: ConfidenceBand | null;
    /** How much of a reason there is to act NOW. Null when the lead has no trigger event at all. */
    timing_score: number | null;
    /** One or two AI sentences over the evidence, and only it. Null until the job has run. */
    why_now_summary: string | null;
    why_now_generated_at: string | null;
    /** Below the team's threshold, so hidden from the list unless it asked to see these. */
    low_confidence: boolean;
    /** The strongest reason, for a card with no summary yet. Present when evidence was loaded. */
    top_evidence?: LeadEvidence | null;
    evidence_count?: number;
    /** The full timeline, detail screens only. */
    evidence?: LeadEvidence[];
    sic_codes: string[];
    source_url: string | null;
    collected_at: string | null;
    enriched_at: string | null;
    outreach_restriction: OutreachRestriction | null;
    /**
     * What kind of subscriber this prospect is for direct-marketing purposes (PL5).
     *
     * **Null means no regime here draws the distinction** — a UAE lead, a lead with no country —
     * which is a different thing from the `unknown` value, and that one warns. Flat strings rather
     * than an enum because nothing on the client branches on the value: the server sends the label
     * and the guidance, and `needs_consent` is what a screen reads.
     */
    subscriber_type: SubscriberType | null;
    has_personal_data: boolean;
    /** The grade against the active ICP, when the lead's `activeScore` was loaded (Phase 9). */
    score?: LeadScoreSummary | null;
    /** Loaded on the detail screens (Phase 8/10); absent on a list row. */
    contacts?: LeadContact[];
    /** The count alone, for a list row (Phase 10). */
    contacts_count?: number;

    // --- Leads Workspace (Phase 10b), each present only when the server loaded it ---
    /** Null means the lead has never been placed, which the board reads as the default column. */
    stage?: { id: number; name: string; outcome: StageOutcome | null } | null;
    tags?: { id: number; name: string }[];
    assignees?: { id: number; name: string }[];
    open_tasks_count?: number;
};

export type DiscoveryRun = {
    id: number;
    source: LeadSource;
    source_label: string;
    status: DiscoveryRunStatus;
    status_label: string;
    icp?: { id: number; name: string } | null;
    params: {
        terms?: string;
        location?: string | null;
        limit?: number;
        icp_id?: number | null;
        industries?: string[];
        /** Words marking a result as the wrong kind of company — the team's trade, its competitors. */
        exclude_terms?: string[];
        /** One line naming who this run looked for, written by the query shaper. */
        customer_description?: string | null;
        /** Whether an AI pass wrote the terms, or the deterministic ICP fallback did. */
        shaped?: boolean;
    } | null;
    requested_limit: number;
    leads_found: number;
    leads_created: number;
    cost_micros: number;
    error_message: string | null;
    started_at: string | null;
    completed_at: string | null;
    created_at: string | null;
    leads?: Lead[];
};

/** A selectable lead source in the discovery picker. */
export type DiscoverySource = {
    value: LeadSource;
    label: string;
    description: string;
    available: boolean;
};

/** A team's ICP as shown in the discovery picker (lightweight). */
export type DiscoveryIcpOption = {
    id: number;
    name: string;
    is_active: boolean;
};

/** The payload broadcast on `teams.{id}` as `discovery-run.progressed`. */
export type DiscoveryRunProgress = {
    run_id: number;
    status: DiscoveryRunStatus;
    percent: number;
    step: string;
    leads_found: number;
};
