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
    confidence?: number | null;
    /** Customers the site names: clients, case studies. Company names only. (v3) */
    proof_customers?: string[];
    problems_solved?: string[];
    /** Situations that make a customer ready to buy. (v3) */
    buying_triggers?: string[];
    /** Who the business says it does not serve. (v3) */
    anti_customers?: string[];
    /** Major units, as the site states them. (v3) */
    pricing_min?: number | null;
    pricing_max?: number | null;
    pricing_currency?: string | null;
    delivery_model?: 'local' | 'regional' | 'national' | 'remote' | null;
    customer_size_range?: string | null;
    /** The page and the words each finding rests on. Absent on reports before v3. */
    evidence?: BackgroundCheckEvidence[];
};

/** One quote supporting one finding. The server has confirmed the quote is on the page. */
export type BackgroundCheckEvidence = {
    field: string;
    value: string;
    page: number;
    quote: string;
    confidence: number;
    url?: string;
};

/**
 * What accepting a report would do to one profile field: fill a blank, leave a match alone, or —
 * where the team entered something else — ask. `current` and `proposed` are already display text.
 */
export type ProfileMappingRow = {
    field: string;
    current: string | null;
    proposed: string;
    state: 'fill' | 'same' | 'conflict';
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
