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
    | 'import'
    | 'manual'
    | 'signal';

/** A discovery provider: the subset of `LeadSource` that can actually be run. */
export type RunnableLeadSource =
    | 'companies_house'
    | 'google_places'
    | 'web_search';

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

/** A raw, company-level lead as discovered from a provider (pre-enrichment). */
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
    sic_codes: string[];
    source_url: string | null;
    collected_at: string | null;
    enriched_at: string | null;
    outreach_restriction: OutreachRestriction | null;
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
