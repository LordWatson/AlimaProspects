// Business Intelligence (AI background check) shared types (Phase 6).

export type BackgroundCheckStatus = 'pending' | 'ready' | 'accepted' | 'failed';

/** The structured findings produced by the `business_intel.background_check` prompt. */
export type BackgroundCheckFindings = {
    summary: string;
    offering: string;
    positioning: string;
    target_customers: string[];
    competitors: string[];
    suggested_industries: string[];
    confidence: number | null;
};

export type BusinessIntelReport = {
    id: number;
    version: number;
    status: BackgroundCheckStatus;
    status_label: string;
    business_name: string | null;
    website: string | null;
    findings: BackgroundCheckFindings | null;
    model: string | null;
    error_message: string | null;
    completed_at: string | null;
    accepted_at: string | null;
};

/** The team's own profile fields shown alongside the AI findings for comparison. */
export type BusinessIntelProfile = {
    company_name: string | null;
    website: string | null;
    industry: string | null;
    value_proposition: string | null;
    products_services: string[];
    customer_examples: string[];
};

/** The payload broadcast on `teams.{id}` as `background-check.progressed`. */
export type BackgroundCheckProgress = {
    status: BackgroundCheckStatus;
    percent: number;
    step: string;
    version: number | null;
};
