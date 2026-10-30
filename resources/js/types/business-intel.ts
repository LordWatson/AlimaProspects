// Business Intelligence (AI background check) shared types (Phase 6).

export type BackgroundCheckStatus = 'pending' | 'ready' | 'accepted' | 'failed';

/**
 * The structured findings produced by the `business_intel.background_check` prompt.
 *
 * `own_industry` and `competitors` describe the team's own business; `customer_industries`,
 * `target_customers` and `decision_maker_titles` describe who buys from it. Keeping the two sides
 * apart is the point — prompt v1 had one `suggested_industries` list meaning both, which is how a
 * team's ICP ended up naming its own trade. Fields are optional because reports written under v1 are
 * still in the database with the old keys.
 */
export type BackgroundCheckFindings = {
    summary: string;
    offering: string;
    positioning: string;
    /** The team's own trade. Shown for confirmation, and excluded from discovery. */
    own_industry?: string | null;
    /** The industries the team's customers are in. This is what seeds the ICP. */
    customer_industries?: string[];
    target_customers?: string[];
    decision_maker_titles?: string[];
    competitors?: string[];
    /** Present only on reports produced by prompt v1, where it meant the team's own trade. */
    suggested_industries?: string[];
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
