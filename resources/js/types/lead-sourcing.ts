// Lead Sourcing (discovery runs + raw leads) shared types (Phase 7).

export type LeadSource = 'companies_house' | 'google_places' | 'web_search';

export type DiscoveryRunStatus = 'pending' | 'running' | 'completed' | 'failed';

/** A raw, company-level lead as discovered from a provider (pre-enrichment). */
export type Lead = {
    id: number;
    source: LeadSource;
    source_label: string;
    status: string;
    status_label: string;
    company_name: string;
    company_number: string | null;
    company_status: string | null;
    company_type: string | null;
    incorporated_on: string | null;
    website: string | null;
    locality: string | null;
    region: string | null;
    postal_code: string | null;
    country: string | null;
    sic_codes: string[];
    source_url: string | null;
    collected_at: string | null;
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
